<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Motor de automatización (chatbot). Portado de includes/flow_engine.php.
 * Recorre el grafo del flujo activo ejecutando nodos hasta una espera o el final.
 */
class FlowEngine
{
    public function __construct(
        protected WhatsAppService $wa,
        protected FlowsMetaService $flowsMeta,
    ) {}

    // ---------- Helpers puros ----------

    protected function findNode(array $nodes, ?string $id): ?array
    {
        foreach ($nodes as $n) {
            if (($n['id'] ?? null) === $id) return $n;
        }
        return null;
    }

    protected function next(array $edges, ?string $nodeId, ?string $handle = null): ?string
    {
        foreach ($edges as $e) {
            if (($e['source'] ?? null) === $nodeId) {
                if ($handle === null || ($e['sourceHandle'] ?? null) === $handle) return $e['target'] ?? null;
            }
        }
        return null;
    }

    /** Sustituye {{{variable}}} por su valor. */
    protected function vars(?string $text, array $vars): string
    {
        return preg_replace_callback('/\{\{\{(\w+)\}\}\}/', fn ($m) => $vars[$m[1]] ?? '', (string) $text);
    }

    /** Normaliza: minúsculas + sin acentos. */
    protected function norm(?string $s): string
    {
        $s = mb_strtolower(trim((string) $s), 'UTF-8');
        $map = [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ];
        return strtr($s, $map);
    }

    /** Construye el objeto interactive (botones/lista) de un nodo send_buttons. */
    protected function buildInteractive(array $d, array $vars): ?array
    {
        $body = trim($this->vars($d['body'] ?? '', $vars));
        if ($body === '') return null;
        $mode    = ($d['mode'] ?? 'button') === 'list' ? 'list' : 'button';
        $options = is_array($d['options'] ?? null) ? $d['options'] : [];

        $ix = ['type' => $mode, 'body' => ['text' => $body]];
        if (!empty($d['header'])) $ix['header'] = ['type' => 'text', 'text' => $this->vars($d['header'], $vars)];
        if (!empty($d['footer'])) $ix['footer'] = ['text' => $this->vars($d['footer'], $vars)];

        if ($mode === 'list') {
            $rows = [];
            foreach ($options as $i => $o) {
                $t = trim($this->vars($o['title'] ?? '', $vars));
                if ($t === '') continue;
                $row = ['id' => 'opt_' . ($o['oid'] ?? $i + 1), 'title' => mb_substr($t, 0, 24)];
                $desc = trim($this->vars($o['description'] ?? '', $vars));
                if ($desc !== '') $row['description'] = mb_substr($desc, 0, 72);
                $rows[] = $row;
                if (count($rows) >= 10) break;
            }
            if (!$rows) return null;
            $label = trim($d['listButton'] ?? '') ?: 'Ver opciones';
            $ix['action'] = ['button' => mb_substr($label, 0, 20), 'sections' => [['rows' => $rows]]];
        } else {
            $btns = [];
            foreach ($options as $i => $o) {
                $t = trim($this->vars($o['title'] ?? '', $vars));
                if ($t === '') continue;
                $btns[] = ['type' => 'reply', 'reply' => ['id' => 'opt_' . ($o['oid'] ?? $i + 1), 'title' => mb_substr($t, 0, 20)]];
                if (count($btns) >= 3) break;
            }
            if (!$btns) return null;
            $ix['action'] = ['buttons' => $btns];
        }
        return $ix;
    }

    protected function condition(array $d, array $vars): bool
    {
        $a  = $this->norm($this->vars($d['variable'] ?? '', $vars));
        $op = $d['operator'] ?? 'contains';

        if ($op === 'exists') return $a !== '';
        if ($op === 'equals' || $op === 'not_equals') {
            $b = $this->norm($d['value'] ?? '');
            return $op === 'equals' ? ($a === $b) : ($a !== $b);
        }

        $kws = $d['keywords'] ?? null;
        if (!is_array($kws)) $kws = $kws !== null ? array_map('trim', explode(',', (string) $kws)) : [];
        if (!$kws && isset($d['value']) && $d['value'] !== '') $kws = [$d['value']];
        foreach ($kws as $w) {
            $w = $this->norm($w);
            if ($w !== '' && mb_strpos($a, $w) !== false) return true;
        }
        return false;
    }

    protected function dig($arr, string $path)
    {
        foreach (explode('.', $path) as $k) {
            if (is_array($arr) && array_key_exists($k, $arr)) $arr = $arr[$k];
            else return null;
        }
        return $arr;
    }

    /** ¿Dentro del horario de atención? (zona horaria de la app) */
    protected function inHours(array $d): bool
    {
        $days = $d['days'] ?? [];
        if (!is_array($days) || !$days) $days = [1, 2, 3, 4, 5];
        $days = array_map('intval', $days);
        if (!in_array((int) date('N'), $days, true)) return false;

        $from = trim($d['from'] ?? '');
        $to   = trim($d['to'] ?? '');
        if ($from === '' || $to === '') return true;

        $now = date('H:i');
        return $from <= $to ? ($now >= $from && $now < $to) : ($now >= $from || $now < $to);
    }

    // ---------- Nodos con efectos ----------

    protected function httpRequest(array $d, array &$vars): void
    {
        $url = trim($this->vars($d['url'] ?? '', $vars));
        if (!preg_match('#^https?://#i', $url)) return;
        $method = strtoupper($d['method'] ?? 'GET');

        $headers = [];
        foreach ($d['headers'] ?? [] as $h) {
            if (!empty($h['key'])) $headers[$h['key']] = $this->vars($h['value'] ?? '', $vars);
        }

        try {
            $req = Http::withHeaders($headers)->timeout(20)->withOptions(['allow_redirects' => ['max' => 3]]);
            if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
                $req = $req->withBody($this->vars($d['body'] ?? '', $vars), $headers['Content-Type'] ?? 'application/json');
            }
            $resp = $req->send($method, $url);
            $raw  = $resp->body();
            $code = $resp->status();
        } catch (\Throwable $e) {
            return;
        }

        $json = json_decode($raw, true);
        foreach ($d['saveTo'] ?? [] as $m) {
            $var = trim($m['variable'] ?? '');
            if ($var === '') continue;
            $path = trim($m['path'] ?? '');
            $val = ($path !== '' && is_array($json)) ? $this->dig($json, $path) : $raw;
            $vars[$var] = is_scalar($val) ? (string) $val : json_encode($val, JSON_UNESCAPED_UNICODE);
        }
        $vars['_httpStatus'] = (string) $code;
    }

    protected function assignAgent(int $contactId, array $d): void
    {
        $target = $d['target'] ?? 'specific';
        if ($target === 'specific') {
            $uid = (int) ($d['user_id'] ?? 0);
            if ($uid) DB::update('UPDATE contacts SET assigned_to = ? WHERE id = ?', [$uid, $contactId]);
            return;
        }
        if (($d['auto'] ?? 'auto') === 'auto') {
            $uid = DB::table('users')->leftJoin('contacts', 'contacts.assigned_to', '=', 'users.id')
                ->groupBy('users.id')->orderByRaw('COUNT(contacts.id) ASC, users.id ASC')
                ->value('users.id');
            if ($uid) DB::update('UPDATE contacts SET assigned_to = ? WHERE id = ?', [(int) $uid, $contactId]);
        } else {
            DB::update('UPDATE contacts SET assigned_to = NULL WHERE id = ?', [$contactId]);
        }
    }

    protected function labels(int $contactId, array $d): void
    {
        $ids = array_map('intval', $d['labels'] ?? []);
        if (!$ids) return;
        $set = DB::table('contact_labels')->where('contact_id', $contactId)->pluck('label_id')->map('intval')->all();
        if (($d['action'] ?? 'add') === 'remove') {
            $set = array_diff($set, $ids);
        } else {
            $set = array_unique(array_merge($set, $ids));
        }
        DB::table('contact_labels')->where('contact_id', $contactId)->delete();
        $rows = array_map(fn ($lid) => ['contact_id' => $contactId, 'label_id' => $lid], $set);
        if ($rows) DB::table('contact_labels')->insertOrIgnore($rows);
    }

    /** Nodo "Consultar base de datos" (solo lectura). */
    protected function query(array $d, array &$vars, ?array $contact = null): void
    {
        $mode = $d['mode'] ?? (!empty($d['query']) ? 'sql' : 'builder');

        if ($mode === 'response') {
            $variable = trim($d['responseVar'] ?? '');
            if ($variable === '' || empty($contact['id'])) return;
            $saveTo = trim($d['saveTo'] ?? '') ?: $variable;
            try {
                $val = DB::table('flow_responses')->where('contact_id', $contact['id'])->where('variable', $variable)
                    ->orderByDesc('created_at')->orderByDesc('id')->value('value');
                if ($val !== null) $vars[$saveTo] = (string) $val;
            } catch (\Throwable $e) { /* ignora */ }
            return;
        }

        if ($mode === 'sql') {
            $sql = trim($d['query'] ?? '');
            if (!preg_match('/^select\s/i', $sql)) return;
            try {
                $row = DB::selectOne($this->vars($sql, $vars));
                if ($row && !empty($d['saveTo'])) $vars[$d['saveTo']] = implode(', ', (array) $row);
            } catch (\Throwable $e) { /* ignora */ }
            return;
        }

        // Modo constructor seguro
        $table  = $d['table'] ?? '';
        $saveTo = trim($d['saveTo'] ?? '');
        if ($table === '' || $saveTo === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) return;

        try {
            $valid = array_map(fn ($c) => $c->Field, DB::select("SHOW COLUMNS FROM `$table`"));
        } catch (\Throwable $e) { return; }
        if (!$valid) return;

        $col = $d['column'] ?? '*';
        $selectExpr = ($col === '*' || $col === '') ? '*' : (in_array($col, $valid, true) ? "`$col`" : null);
        if ($selectExpr === null) return;

        $sql = "SELECT $selectExpr FROM `$table`";
        $params = [];
        $whereCol = $d['whereColumn'] ?? '';
        if ($whereCol !== '') {
            if (!in_array($whereCol, $valid, true)) return;
            $value = $this->vars($d['whereValue'] ?? '', $vars);
            if (($d['operator'] ?? 'eq') === 'contains') {
                $sql .= " WHERE `$whereCol` LIKE ?";
                $params[] = '%' . $value . '%';
            } else {
                $opSql = ['eq' => '=', 'ne' => '<>', 'gt' => '>', 'lt' => '<', 'gte' => '>=', 'lte' => '<='][$d['operator'] ?? 'eq'] ?? '=';
                $sql .= " WHERE `$whereCol` $opSql ?";
                $params[] = $value;
            }
        }
        $sql .= ' LIMIT 1';

        try {
            $row = DB::selectOne($sql, $params);
            if ($row) $vars[$saveTo] = implode(', ', (array) $row);
        } catch (\Throwable $e) { /* ignora */ }
    }

    /** Guarda una respuesta capturada por el bot (vista "Respuestas del bot"). */
    public function saveResponse(int $contactId, $flowId, $sessionId, string $variable, $value): void
    {
        $variable = trim($variable);
        if ($variable === '') return;
        try {
            DB::table('flow_responses')->insert([
                'contact_id' => $contactId,
                'flow_id'    => $flowId ?: null,
                'session_id' => $sessionId ?: null,
                'variable'   => mb_substr($variable, 0, 64),
                'value'      => (string) $value,
            ]);
        } catch (\Throwable $e) { /* ignora */ }
    }

    protected function saveSession(array $s): void
    {
        DB::table('flow_sessions')->where('id', $s['id'])->update([
            'current_node' => $s['current_node'],
            'variables'    => $s['variables'],
            'status'       => $s['status'],
            'resume_at'    => $s['resume_at'] ?? null,
        ]);
    }

    // ---------- Núcleo ----------

    /** Recorre el grafo desde $startId ejecutando nodos hasta una espera o el final. */
    public function run(array &$s, array $flow, ?string $startId, array $contact): void
    {
        $graph = json_decode($flow['graph'] ?: '{}', true);
        $nodes = $graph['nodes'] ?? [];
        $edges = $graph['edges'] ?? [];
        $vars  = json_decode($s['variables'] ?: '{}', true) ?: [];
        $cur = $startId;
        $guard = 0;

        // Todo lo que envíe el bot se guarda en el ticket del que vino el mensaje.
        $t = ['channel' => 'whatsapp', 'status' => 'sent'];
        if (!empty($contact['ticket_id'])) $t['ticket_id'] = $contact['ticket_id'];

        while ($cur && $guard++ < 60) {
            $node = $this->findNode($nodes, $cur);
            if (!$node) break;
            $t = $node['data']['type'] ?? '';
            $d = $node['data'] ?? [];

            if ($t === 'send_message') {
                $msg = $this->vars($d['message'] ?? '', $vars);
                if (trim($msg) !== '') {
                    [$code, $res] = $this->wa->sendText($contact['wa_id'], $msg);
                    if (!empty($res['messages'][0]['id'])) {
                        ChatService::storeMessage($contact['id'], $contact['wa_id'], 'out', 'text', $msg, $t + ['wamid' => $res['messages'][0]['id']]);
                    }
                }
            } elseif ($t === 'send_template') {
                [$name, $lang] = array_pad(explode('|', $d['template'] ?? ''), 2, '');
                if ($name) $this->wa->sendTemplate($contact['wa_id'], $name, $lang ?: 'es');
            } elseif ($t === 'send_buttons') {
                $ix = $this->buildInteractive($d, $vars);
                if (!$ix) { $cur = $this->next($edges, $cur); continue; }
                [$c, $r] = $this->wa->sendInteractive($contact['wa_id'], $ix);
                if (!empty($r['messages'][0]['id'])) {
                    ChatService::storeMessage($contact['id'], $contact['wa_id'], 'out', 'interactive', $ix['body']['text'] ?? '', $t + ['wamid' => $r['messages'][0]['id'], 'payload' => json_encode($ix, JSON_UNESCAPED_UNICODE)]);
                }
                $s['current_node'] = $cur; $s['status'] = 'active'; $s['variables'] = json_encode($vars); $s['resume_at'] = null;
                $this->saveSession($s);
                return;
            } elseif ($t === 'response_saver') {
                if (!empty($d['prompt'])) {
                    $p = $this->vars($d['prompt'], $vars);
                    [$c, $r] = $this->wa->sendText($contact['wa_id'], $p);
                    if (!empty($r['messages'][0]['id'])) {
                        ChatService::storeMessage($contact['id'], $contact['wa_id'], 'out', 'text', $p, $t + ['wamid' => $r['messages'][0]['id']]);
                    }
                }
                $s['current_node'] = $cur; $s['status'] = 'active'; $s['variables'] = json_encode($vars); $s['resume_at'] = null;
                $this->saveSession($s);
                return;
            } elseif ($t === 'condition') {
                $cur = $this->next($edges, $cur, $this->condition($d, $vars) ? 'yes' : 'no');
                continue;
            } elseif ($t === 'route_type') {
                $mt = $vars['messageType'] ?? 'text';
                $next = $this->next($edges, $cur, $mt) ?? $this->next($edges, $cur, 'other');
                $cur = $next;
                continue;
            } elseif ($t === 'business_hours') {
                $cur = $this->next($edges, $cur, $this->inHours($d) ? 'in' : 'out');
                continue;
            } elseif ($t === 'set_labels') {
                $this->labels($contact['id'], $d);
            } elseif ($t === 'send_form') {
                $fid = (int) ($d['form_id'] ?? 0);
                if ($fid) {
                    $form = DB::selectOne('SELECT * FROM forms WHERE id = ?', [$fid]);
                    if ($form && !empty($form->meta_flow_id)) {
                        $ftoken = 'f' . $fid . '_' . $contact['id'];
                        $bodyText = trim($this->vars($d['body'] ?? '', $vars)) ?: (trim($this->vars($form->description ?? '', $vars)) ?: '¡Hola! Pulsa abajo para rellenar nuestro formulario.');
                        $cta = mb_substr(trim($d['cta'] ?? '') ?: 'Ver formulario', 0, 30);
                        [$fc, $fr] = $this->flowsMeta->send($contact['wa_id'], $form->meta_flow_id, $ftoken, $bodyText, $cta);
                        if (!empty($fr['messages'][0]['id'])) {
                            ChatService::storeMessage($contact['id'], $contact['wa_id'], 'out', 'interactive', '📋 Formulario: ' . $form->name, $t + ['wamid' => $fr['messages'][0]['id']]);
                        }
                    }
                }
            } elseif ($t === 'http_request') {
                $this->httpRequest($d, $vars);
            } elseif ($t === 'agent_transfer') {
                $this->assignAgent($contact['id'], $d);
                DB::update('UPDATE contacts SET bot_off = 1 WHERE id = ?', [$contact['id']]);
            } elseif ($t === 'disable_autoreply') {
                $vars['_autoreply'] = 'off';
            } elseif ($t === 'reset_session') {
                $vars = ['senderName' => $vars['senderName'] ?? '', 'senderMobile' => $vars['senderMobile'] ?? ''];
            } elseif ($t === 'delay') {
                $sec = max(0, (int) ($d['seconds'] ?? 0));
                $next = $this->next($edges, $cur);
                $s['current_node'] = $next; $s['status'] = 'waiting';
                $s['resume_at'] = date('Y-m-d H:i:s', time() + $sec); $s['variables'] = json_encode($vars);
                $this->saveSession($s);
                return; // lo retoma el cron
            } elseif ($t === 'mysql_query') {
                $this->query($d, $vars, $contact);
            }
            $cur = $this->next($edges, $cur);
        }

        $s['status'] = 'done'; $s['variables'] = json_encode($vars); $s['resume_at'] = null;
        $this->saveSession($s);
    }

    /**
     * Punto de entrada: gestiona un mensaje entrante.
     * $replyId = id del botón/fila pulsado (opt_<oid>), si lo hay.
     */
    public function handle(array $contact, string $text, ?string $senderName, string $type = 'text', ?string $replyId = null): void
    {
        $s = (array) DB::selectOne("SELECT * FROM flow_sessions WHERE contact_id=? AND status='active' ORDER BY id DESC LIMIT 1", [$contact['id']]);
        if ($s && isset($s['id'])) {
            $flow = (array) DB::selectOne('SELECT * FROM flows WHERE id=?', [$s['flow_id']]);
            if (empty($flow['id']) || (int) $flow['active'] !== 1) {
                DB::update("UPDATE flow_sessions SET status='done' WHERE id=?", [$s['id']]);
                return;
            }
            $graph = json_decode($flow['graph'] ?: '{}', true);
            $edges = $graph['edges'] ?? [];
            $node  = $this->findNode($graph['nodes'] ?? [], $s['current_node']);
            $nt = $node['data']['type'] ?? '';

            if ($nt === 'response_saver') {
                $vars = json_decode($s['variables'] ?: '{}', true) ?: [];
                $var = $node['data']['variable'] ?? '';
                if ($var) { $vars[$var] = $text; $this->saveResponse($contact['id'], $s['flow_id'], $s['id'], $var, $text); }
                $vars['senderMessage'] = $text;
                $vars['messageType'] = $type;
                $s['variables'] = json_encode($vars);
                $next = $this->next($edges, $s['current_node']);
                $this->run($s, $flow, $next, $contact);
            } elseif ($nt === 'send_buttons') {
                $vars = json_decode($s['variables'] ?: '{}', true) ?: [];
                if (!empty($node['data']['saveTo'])) {
                    $vars[$node['data']['saveTo']] = $text;
                    $this->saveResponse($contact['id'], $s['flow_id'], $s['id'], $node['data']['saveTo'], $text);
                }
                $vars['senderMessage'] = $text;
                $vars['messageType'] = $type;
                $s['variables'] = json_encode($vars);
                $next = ($replyId && str_starts_with($replyId, 'opt_')) ? $this->next($edges, $s['current_node'], $replyId) : null;
                if (!$next) $next = $this->next($edges, $s['current_node'], 'fallback');
                if (!$next) $next = $this->next($edges, $s['current_node']);
                $this->run($s, $flow, $next, $contact);
            }
            return;
        }

        // Bot pausado (transferido a humano): no responder.
        if ((int) DB::table('contacts')->where('id', $contact['id'])->value('bot_off') === 1) return;

        // Iniciar el flujo activo
        $flow = (array) DB::selectOne("SELECT * FROM flows WHERE active=1 ORDER BY updated_at DESC LIMIT 1");
        if (empty($flow['id'])) return;
        $vars = ['senderName' => $senderName ?: '', 'senderMessage' => $text, 'senderMobile' => $contact['wa_id'], 'messageType' => $type];
        $sid = DB::table('flow_sessions')->insertGetId([
            'contact_id' => $contact['id'], 'flow_id' => $flow['id'], 'current_node' => 'initial',
            'variables' => json_encode($vars), 'status' => 'active',
        ]);
        $s = ['id' => $sid, 'contact_id' => $contact['id'], 'flow_id' => $flow['id'], 'current_node' => 'initial', 'variables' => json_encode($vars), 'status' => 'active', 'resume_at' => null];
        $this->run($s, $flow, 'initial', $contact);
    }
}
