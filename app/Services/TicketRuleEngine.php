<?php

namespace App\Services;

use App\Models\TicketRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Motor de REGLAS AUTOMÁTICAS de tickets («Flujo de trabajo» de osTicket).
 *
 * Se ejecuta al crear un ticket: recorre las reglas activas por orden y, si sus
 * condiciones casan, aplica sus acciones (asignar agente / categoría / prioridad).
 * Una regla puede marcar «stop» para que no se sigan evaluando las siguientes.
 *
 * REGLA DE ORO: esto es una comodidad, no un requisito. Si algo falla, se registra
 * y el ticket se queda como estaba; jamás debe tumbar la creación del ticket.
 */
class TicketRuleEngine
{
    public function __construct(protected TicketService $tickets) {}

    /**
     * Aplica las reglas a un ticket recién creado.
     * $ctx: ['subject'=>…, 'body'=>…, 'email'=>…, 'channel'=>…]
     * Devuelve los nombres de las reglas que se aplicaron.
     */
    public function apply(int $ticketId, array $ctx): array
    {
        $aplicadas = [];
        try {
            $rules = TicketRule::where('active', true)->orderBy('position')->orderBy('id')->get();
            if ($rules->isEmpty()) return [];

            $channel = (string) ($ctx['channel'] ?? '');

            foreach ($rules as $rule) {
                // Regla limitada a un canal concreto.
                if ($rule->channel !== 'any' && $rule->channel !== $channel) continue;
                if (!$this->matches($rule, $ctx)) continue;

                $this->runActions($rule, $ticketId);
                $aplicadas[] = $rule->name;

                if ($rule->stop) break;
            }
        } catch (\Throwable $e) {
            Log::warning('TicketRuleEngine: fallo aplicando reglas', ['ticket' => $ticketId, 'error' => $e->getMessage()]);
        }
        return $aplicadas;
    }

    /** ¿Casan las condiciones? Según `match`: basta una (any) o hacen falta todas (all). */
    protected function matches(TicketRule $rule, array $ctx): bool
    {
        $conds = $rule->conditions ?: [];
        if (!$conds) return false;   // sin condiciones no se aplica a todo por accidente

        $all = $rule->match === 'all';
        foreach ($conds as $c) {
            $ok = $this->test($ctx, (string) ($c['field'] ?? ''), (string) ($c['op'] ?? ''), (string) ($c['value'] ?? ''));
            if ($all && !$ok) return false;
            if (!$all && $ok)  return true;
        }
        return $all;   // «todas»: llegó al final sin fallar → casa. «cualquiera»: ninguna casó.
    }

    /** Evalúa UNA condición contra el contexto del ticket (sin distinguir mayúsculas). */
    protected function test(array $ctx, string $field, string $op, string $value): bool
    {
        $value = mb_strtolower(trim($value));
        if ($value === '') return false;

        $subject = mb_strtolower((string) ($ctx['subject'] ?? ''));
        $body    = mb_strtolower((string) ($ctx['body'] ?? ''));
        $email   = mb_strtolower((string) ($ctx['email'] ?? ''));

        $hay = match ($field) {
            'subject' => $subject,
            'body'    => $body,
            'email'   => $email,
            'domain'  => (string) substr((string) strrchr($email, '@'), 1),
            default   => '',
        };
        if ($hay === '') return false;

        return match ($op) {
            'contains'     => str_contains($hay, $value),
            'not_contains' => !str_contains($hay, $value),
            'equals'       => $hay === $value,
            'starts_with'  => str_starts_with($hay, $value),
            default        => false,
        };
    }

    /** Aplica las acciones de la regla al ticket. */
    protected function runActions(TicketRule $rule, int $ticketId): void
    {
        $a = $rule->actions ?: [];

        // Asignar agente: pasa por TicketService para que quede en el historial y avise.
        if (!empty($a['assign_to'])) {
            $this->tickets->assign($ticketId, (int) $a['assign_to']);
        }

        $upd = [];
        if (!empty($a['category_id'])) $upd['category_id'] = (int) $a['category_id'];
        if (!empty($a['priority']) && array_key_exists($a['priority'], TicketService::priorities())) {
            $upd['priority'] = $a['priority'];
        }
        if ($upd) {
            DB::table('tickets')->where('id', $ticketId)->update($upd);
            foreach ($upd as $campo => $valor) {
                $this->tickets->event($ticketId, $campo === 'priority' ? 'priority' : 'category', null, (string) $valor, null);
            }
        }
    }
}
