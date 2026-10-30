<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una etiqueta (o antena) afectada de una garantía: dónde estaba al comprobarla, su
 * modelo, el producto del portal que es (referencia AEME) y cómo quedó al revisarla.
 */
class WarrantyItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'online'          => 'boolean',
        'model_manual'    => 'boolean',
        'warnings'        => 'array',
        'broken_cases'    => 'array',
        'battery_changed' => 'boolean',
        'last_commu_at'   => 'datetime',
        'checked_at'      => 'datetime',
        'reviewed_at'     => 'datetime',
    ];

    /*
     * Soluciones de la revisión. «photos»: pide las fotos del dispositivo (las de su tipo,
     * ver PHOTO_TYPES). «zkong»: puede ir a ZKONG (se decide si se manda y con qué fallo);
     * por eso también pide fotos: ZKONG las exige aunque la etiqueta no tenga ni un golpe.
     * Lo demás que pide cada una lo valida el controlador.
     */
    public const RESOLUTIONS = [
        'pendiente'    => ['label' => 'Pendiente de revisar',   'photos' => false, 'zkong' => false],
        'correcta'     => ['label' => 'Correcta',               'photos' => false, 'zkong' => false],
        'bateria'      => ['label' => 'Batería baja',           'photos' => false, 'zkong' => false],
        'recuperada'   => ['label' => 'Recuperada en remoto',   'photos' => false, 'zkong' => false],
        'reparada'     => ['label' => 'Reparada',               'photos' => false, 'zkong' => false],
        'no_reproduce' => ['label' => 'No reproduce el fallo',  'photos' => true,  'zkong' => true],
        'defectuosa'   => ['label' => 'Defectuosa',             'photos' => true,  'zkong' => true],
        'danada'       => ['label' => 'Dañada por el cliente',  'photos' => true,  'zkong' => false],
        'desechada'    => ['label' => 'Desechada',              'photos' => false, 'zkong' => false],
    ];

    // Quién paga cada dispositivo: decide en qué presupuesto o albarán va.
    public const COVERAGES = [
        'garantia' => 'Garantía',
        'cliente'  => 'Paga el cliente',
        'pilas'    => 'Pilas',
    ];

    // Lo que se propone según la solución (el técnico lo cambia si hace falta). Desechada
    // no tiene propuesta: depende del motivo, así que se elige siempre.
    public const DEFAULT_COVERAGE = [
        'correcta' => 'garantia', 'recuperada' => 'garantia', 'reparada' => 'garantia',
        'no_reproduce' => 'garantia', 'defectuosa' => 'garantia', 'danada' => 'cliente', 'bateria' => 'pilas',
    ];

    // Las que pueden ir a ZKONG: qué se hace con ellas.
    public const ZKONG_DECISIONS = [
        'decidir'   => 'Pendiente de decidir',
        'enviar'    => 'Enviar a ZKONG',
        'no_enviar' => 'No enviar a ZKONG',
    ];

    // Dañada por el cliente: por qué no la cubre la garantía (los de htdocs/garantias).
    public const BROKEN_CASES = [
        'misuse'                 => 'Mal uso',
        'incorrect_installation' => 'Instalación incorrecta',
        'unauthorized_opening'   => 'Abierta sin autorización',
        'modifications'          => 'Modificada',
        'serial_altered'         => 'Número de serie alterado',
        'impacts'                => 'Golpes o caídas',
        'liquids'                => 'Líquidos',
        'extreme_heat'           => 'Calor extremo',
        'electrical_stress'      => 'Sobretensión eléctrica',
        'force_majeure'          => 'Fuerza mayor',
        'unsuitable_environment' => 'Entorno no adecuado',
    ];

    // Fotos que pide cada tipo de dispositivo.
    public const PHOTO_TYPES = [
        'esl' => [
            'front'             => 'Frontal (código visible)',
            'back_batteries'    => 'Trasera con pilas',
            'back_no_batteries' => 'Trasera sin pilas',
        ],
        'ap' => [
            'front' => 'Frontal',
            'back'  => 'Trasera (MAC visible)',
        ],
    ];

    public static function photoTypes(string $kind): array
    {
        return self::PHOTO_TYPES[$kind] ?? self::PHOTO_TYPES['esl'];
    }
}
