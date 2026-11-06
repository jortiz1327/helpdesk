<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Qué permiso pide cada acción de Gestión de garantías (el de la ruta, warranties.access,
 * solo deja consultar). Una tabla por endpoint; lo que no está aquí es consulta.
 */
class WarrantyPermissions
{
    public const ACTIONS = [
        'warranties' => [
            'save' => 'warranties.edit', 'note' => 'warranties.edit', 'bulk' => 'warranties.edit',
            'labels_add' => 'warranties.edit', 'labels_recheck' => 'warranties.edit', 'label_model' => 'warranties.edit',
            'label_delete' => 'warranties.edit', 'step' => 'warranties.edit', 'cloud_label' => 'warranties.edit', 'tracking' => 'warranties.edit',
            'review' => 'warranties.review', 'photo_upload' => 'warranties.review', 'photo_from_ticket' => 'warranties.review',
            'photo_delete' => 'warranties.review',
            'delete' => 'warranties.delete',
        ],
        'docs' => [
            'report' => 'warranties.documents', 'email' => 'warranties.documents', 'send_email' => 'warranties.documents',
            'finance' => 'warranties.documents', 'portal_drafts' => 'warranties.documents',
            'replacements_lookup' => 'warranties.documents', 'replacements_save' => 'warranties.documents',
            'replacements_ticket' => 'warranties.positions_ticket',
        ],
        'zkong' => [
            'create' => 'warranties.zkong', 'add' => 'warranties.zkong', 'remove' => 'warranties.zkong', 'send' => 'warranties.zkong',
            'shipping' => 'warranties.zkong', 'receive' => 'warranties.zkong', 'cancel' => 'warranties.zkong', 'result' => 'warranties.zkong',
            'close' => 'warranties.zkong', 'reopen' => 'warranties.zkong', 'note' => 'warranties.zkong', 'delete' => 'warranties.zkong',
            'rma' => 'warranties.zkong', 'return' => 'warranties.zkong',
        ],
    ];

    private const QUE = [
        'warranties.edit' => 'editar garantías', 'warranties.review' => 'revisar dispositivos',
        'warranties.documents' => 'preparar los documentos', 'warranties.positions_ticket' => 'crear el ticket de posiciones',
        'warranties.zkong' => 'gestionar las remesas de ZKONG', 'warranties.delete' => 'borrar garantías',
    ];

    /** La respuesta 403 si esta acción pide un permiso que el usuario no tiene; si no, null. */
    public static function deny(?User $u, string $area, string $action): ?JsonResponse
    {
        $p = self::ACTIONS[$area][$action] ?? null;
        if (! $p || $u?->can($p)) return null;
        return response()->json(['ok' => false, 'error' => 'No tienes permiso para ' . (self::QUE[$p] ?? 'esto')], 403);
    }

    /** Lo que puede hacer, para que la interfaz enseñe solo esos botones. */
    public static function of(?User $u): array
    {
        $out = [];
        foreach (['edit', 'review', 'documents', 'positions_ticket', 'zkong', 'delete', 'config'] as $k) {
            $out[$k] = (bool) $u?->can("warranties.{$k}");
        }
        return $out;
    }
}
