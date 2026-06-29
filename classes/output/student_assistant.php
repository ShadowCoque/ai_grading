<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Renderable for the floating student assistant panel.
 *
 * @package    local_ai_grading
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_grading\output;

defined('MOODLE_INTERNAL') || die();

use renderable;
use renderer_base;
use templatable;

/**
 * Exports the published feedback list to the floating assistant template.
 */
class student_assistant implements renderable, templatable {
    /** @var array List of published feedback entries. */
    private $feedback;

    /** @var int VPL instance of the current page, when it is a VPL activity. */
    private $currentvplid;

    /**
     * @param array $feedback Published feedback entries.
     * @param int $currentvplid VPL instance id of the current page (0 if none).
     */
    public function __construct(array $feedback, int $currentvplid = 0) {
        $this->feedback = $feedback;
        $this->currentvplid = $currentvplid;
    }

    /**
     * Provides template data.
     *
     * @param renderer_base $output Renderer instance.
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        global $USER, $PAGE;

        $courseid = ($PAGE->course && $PAGE->course->id) ? (int)$PAGE->course->id : 0;

        // El feedback completo solo se muestra dentro de la ventana del asistente cuando el
        // estudiante está en la página de edición de su actividad VPL. Desde cualquier otra
        // página solo se ve el resumen con el botón para ir a esa página. Este hook corre al
        // final de la generación de la página, cuando $PAGE->url ya apunta a edit.php.
        $onvpledit = $PAGE->url
            && strpos($PAGE->url->out_omit_querystring(), '/mod/vpl/forms/edit.php') !== false;

        // cmid de la actividad VPL que se está editando (parámetro id de edit.php).
        $editcmid = 0;
        if ($onvpledit) {
            $editcmid = (int)$PAGE->url->get_param('id');
            if ($editcmid <= 0) {
                $editcmid = optional_param('id', 0, PARAM_INT);
            }
        }

        // vplid de la actividad actual. Lo resolvemos por el cmid de la URL de edición (no
        // depende del momento en que se construyó la navegación, a diferencia de lib.php);
        // si no estamos en edit.php usamos el que detectó lib.php para resaltar la actividad.
        $currentvplid = (int)$this->currentvplid;
        if ($editcmid > 0) {
            foreach ($this->feedback as $candidate) {
                if ((int)$candidate['cmid'] === $editcmid) {
                    $currentvplid = (int)$candidate['vplid'];
                    break;
                }
            }
        }

        // Detalle completo de la actividad actual (solo en la página de edición de VPL).
        $detail = null;
        if ($onvpledit && $currentvplid > 0 && $courseid > 0) {
            $full = \local_ai_grading\local\grading_service::get_student_feedback(
                $courseid,
                (int)$USER->id,
                $currentvplid
            );
            if ($full !== null) {
                $full['phases'] = self::phase_tags(
                    !empty($full['reviewedByTeacher']),
                    !empty($full['modifiedByTeacher'])
                );
                $detail = $full;
                // Mostrar el detalle completo aquí (el asistente se abre en la página de
                // edición de la actividad VPL) cuenta como "leído": marca la retroalimentación
                // para que el badge de no leídos deje de aparecer. Antes solo lo hacía
                // feedback.php, que no es la ruta real desde la tarjeta del asistente. Es
                // idempotente: solo guarda la primera vez que se abre.
                \local_ai_grading\local\grading_service::mark_student_feedback_read(
                    $courseid,
                    (int)$USER->id,
                    $currentvplid
                );
            }
        }

        // Flag the activity matching the current page and bubble it to the top.
        $items = [];
        $unread = 0;
        foreach ($this->feedback as $item) {
            $item['isCurrent'] = $currentvplid > 0 && (int)$item['vplid'] === $currentvplid;
            // Si en esta misma carga se está mostrando (y marcando como leído) el detalle de
            // esta actividad, refléjalo ya para que el punto/contador de no leídos desaparezca
            // sin esperar a la siguiente navegación.
            if ($detail !== null && !empty($item['isCurrent'])) {
                $item['read'] = true;
                $item['unread'] = false;
            }
            $item['phases'] = self::phase_tags(!empty($item['reviewedByTeacher']), !empty($item['modifiedByTeacher']));
            if (!empty($item['unread'])) {
                $unread++;
            }
            $items[] = $item;
        }
        usort($items, static function(array $a, array $b): int {
            return ($b['isCurrent'] ? 1 : 0) <=> ($a['isCurrent'] ? 1 : 0);
        });

        // En vista de detalle, el resto de actividades se listan aparte como resumen.
        $others = [];
        if ($detail !== null) {
            foreach ($items as $item) {
                if (empty($item['isCurrent'])) {
                    $others[] = $item;
                }
            }
        }

        return [
            'firstname' => $USER->firstname,
            'count' => count($items),
            'hasmany' => count($items) > 1,
            'unreadcount' => $unread,
            'hasunread' => $unread > 0,
            'feedback' => $items,
            'fullview' => $detail !== null,
            'detail' => $detail,
            'others' => $others,
            'hasothers' => !empty($others),
        ];
    }

    /**
     * Builds the three student-facing phase tags for a published result.
     *
     * @param bool $reviewed Whether the teacher reviewed the result.
     * @param bool $modified Whether the teacher modified the AI proposal.
     * @return array
     */
    private static function phase_tags(bool $reviewed, bool $modified): array {
        return [
            [
                'label' => get_string('phase:gradedai', 'local_ai_grading'),
                'cls' => 'aig-phase-tag--ai',
                'on' => true,
            ],
            [
                'label' => get_string('phase:reviewed', 'local_ai_grading'),
                'cls' => 'aig-phase-tag--reviewed',
                'on' => $reviewed,
            ],
            [
                'label' => get_string('phase:modified', 'local_ai_grading'),
                'cls' => 'aig-phase-tag--modified',
                'on' => $modified,
            ],
        ];
    }
}
