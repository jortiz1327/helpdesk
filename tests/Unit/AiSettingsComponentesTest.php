<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** La pantalla de Ajustes › Asistente IA se rompía entera («Algo ha fallado») si usaba un componente que ya no estaba definido. */
class AiSettingsComponentesTest extends TestCase
{
    public function test_cada_componente_que_se_usa_esta_definido(): void
    {
        $js = file_get_contents(__DIR__ . '/../../frontend/src/components/AiSettings.jsx');
        preg_match_all('/<(Ai[A-Za-z]+)[\s\/>]/', $js, $m);
        $this->assertNotEmpty($m[1]);
        foreach (array_unique($m[1]) as $nombre) {
            $this->assertMatchesRegularExpression('/function ' . $nombre . '\s*\(/', $js, "AiSettings.jsx usa <$nombre> pero no lo define");
        }
    }
}
