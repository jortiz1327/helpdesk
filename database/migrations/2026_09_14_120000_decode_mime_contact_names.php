<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Arregla los NOMBRES de contacto que se guardaron codificados en MIME
 * («=?UTF-8?Q?Fusi=C3=B3n_Ribera?=» en vez de «Fusión Ribera»).
 *
 * El sondeo IMAP guardaba el `personal` de la dirección tal cual; desde este cambio
 * MailService lo decodifica al entrar. Esto corrige lo que ya estaba en BBDD, en
 * `contacts` y en la cuarentena de correo.
 *
 * Idempotente: solo toca filas con encoded-words, y un nombre ya decodificado no las
 * tiene, así que repetirla no cambia nada. La lógica va copiada aquí a propósito, en
 * vez de llamar a MailService: una migración no debe cambiar de comportamiento si
 * mañana cambia el servicio.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->decodificar('contacts', 'id', 'name');
        $this->decodificar('email_quarantine', 'id', 'from_name');
    }

    public function down(): void
    {
        // Sin vuelta atrás: el valor codificado no aporta nada que haya que recuperar.
    }

    private function decodificar(string $tabla, string $pk, string $columna): void
    {
        DB::table($tabla)
            ->where($columna, 'like', '%=?%?=%')
            ->orderBy($pk)
            ->select([$pk, $columna])
            ->chunkById(500, function ($filas) use ($tabla, $pk, $columna) {
                foreach ($filas as $fila) {
                    $original = (string) $fila->{$columna};
                    $limpio   = self::decode($original);

                    if ($limpio === '' || $limpio === $original) {
                        continue;   // no decodifica: mejor dejarlo que borrar el nombre
                    }

                    DB::table($tabla)->where($pk, $fila->{$pk})
                        ->update([$columna => mb_substr($limpio, 0, 255)]);
                }
            }, $pk);
    }

    private static function decode(string $raw): string
    {
        $s = trim($raw);
        if ($s === '' || stripos($s, '=?') === false) return $s;
        $out = @iconv_mime_decode($s, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if ($out === false || trim((string) $out) === '') $out = @mb_decode_mimeheader($s);
        return trim(($out !== false && $out !== null) ? (string) $out : $s);
    }
};
