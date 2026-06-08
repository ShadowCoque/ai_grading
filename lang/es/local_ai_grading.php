<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Spanish strings for AI Grading.
 *
 * @package    local_ai_grading
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'AI Grading';
$string['pagetitle'] = 'Evaluación Automática con IA';
$string['pagesubtitle'] = 'Selecciona una actividad VPL, configura los criterios de evaluación y prueba antes de aplicar.';
$string['coursecontext'] = 'Contexto del curso';
$string['simulationmode'] = 'Modo simulación';
$string['activitysection'] = 'Actividad VPL';
$string['activitysectionhelp'] = 'Selecciona una actividad VPL mock. El resto de la interfaz se actualiza como si estuviera conectada a datos de Moodle.';
$string['criteriasection'] = 'Criterios de evaluación';
$string['criteriasectionhelp'] = 'Define la rúbrica que guiará las referencias manuales y la evaluación simulada con IA.';
$string['promptsection'] = 'Prompt base y directrices para la IA';
$string['promptsectionhelp'] = 'Escribe instrucciones generales de evaluación. La vista previa muestra cómo esta configuración alimentaría luego al servicio externo de IA.';
$string['manualsection'] = 'Calificación manual de referencia';
$string['manualsectionhelp'] = 'Carga un caso mock de estudiante, califícalo por criterio y conserva referencias en el historial temporal de la página.';
$string['aitestsection'] = 'Prueba de evaluación con IA';
$string['aitestsectionhelp'] = 'Ejecuta una evaluación IA simulada con respuestas mock coherentes. Conserva las pruebas útiles o descártalas.';
$string['finalsection'] = 'Acción final';
$string['finalsectionhelp'] = 'Esta fase solo prepara la pantalla de configuración. Aplicar la evaluación muestra un placeholder.';
$string['applybutton'] = 'Aplicar evaluación a estudiantes';
$string['ai_grading:view'] = 'Ver AI Grading';
$string['ai_grading:manage'] = 'Configurar AI Grading';
$string['privacy:metadata'] = 'AI Grading almacena configuraciones de evaluación, IDs de estudiantes, IDs de entregas VPL, observaciones docentes y resultados de pruebas IA.';
$string['privacy:metadata:local_ai_grading_config'] = 'Configuraciones de evaluación creadas por docentes para actividades VPL.';
$string['privacy:metadata:local_ai_grading_manual'] = 'Calificaciones manuales de referencia asociadas a estudiantes y entregas VPL.';
$string['privacy:metadata:local_ai_grading_mancrit'] = 'Detalles por criterio de una calificación manual.';
$string['privacy:metadata:local_ai_grading_aitest'] = 'Pruebas de evaluación IA ejecutadas por docentes.';
$string['privacy:metadata:local_ai_grading_aitcrit'] = 'Detalles por criterio de una prueba IA.';
$string['privacy:metadata:local_ai_grading_result'] = 'Resultados reales de evaluación IA para fases posteriores.';
$string['privacy:metadata:local_ai_grading_rescrit'] = 'Detalles por criterio de resultados reales de evaluación.';
$string['privacy:metadata:courseid'] = 'ID del curso.';
$string['privacy:metadata:vplid'] = 'ID de la actividad VPL.';
$string['privacy:metadata:teacherid'] = 'ID del docente que creó o actualizó la configuración.';
$string['privacy:metadata:studentid'] = 'ID del estudiante evaluado.';
$string['privacy:metadata:submissionid'] = 'ID de la entrega VPL.';
$string['privacy:metadata:observations'] = 'Observaciones o retroalimentación guardadas por docentes o por el servicio IA.';
$string['privacy:metadata:grades'] = 'Notas calculadas o guardadas durante la evaluación.';

$string['activitylabel'] = 'Actividad';
$string['activityhelp'] = 'Opciones VPL mock para esta primera fase';
$string['activitypreview'] = 'Vista previa de la actividad';
$string['addcriterion'] = 'Agregar criterio';
$string['addlevel'] = 'Agregar nivel';
$string['aicase'] = 'Caso de prueba';
$string['aidiscard'] = 'Descartar resultado';
$string['aifeedback'] = 'Retroalimentación general';
$string['aikeep'] = 'Conservar resultado';
$string['airun'] = 'Ejecutar prueba IA';
$string['aiscore'] = 'Nota IA';
$string['aitotal'] = 'Nota total';
$string['applyplaceholder'] = 'Placeholder: la siguiente fase abrirá la gestión de resultados y aplicará la evaluación IA configurada.';
$string['active'] = 'Activa';
$string['criteriatotal'] = 'Peso total';
$string['criteriondescription'] = 'Descripción del criterio';
$string['criterionname'] = 'Nombre del criterio';
$string['delete'] = 'Eliminar';
$string['description'] = 'Descripción';
$string['discard'] = 'Descartar';
$string['editinline'] = 'Edita directamente en este bloque';
$string['history'] = 'Historial';
$string['keepasactive'] = 'Marcar activa';
$string['levelname'] = 'Nombre del nivel';
$string['loadcase'] = 'Cargar caso';
$string['manualnew'] = 'Trabajar sobre una nueva referencia';
$string['manualobservations'] = 'Observaciones generales';
$string['manualsave'] = 'Guardar calificación manual';
$string['manualscore'] = 'Calificación manual';
$string['manualselectmode'] = 'Modo de selección';
$string['manualspecificstudent'] = 'Estudiante específico';
$string['manualrandomstudent'] = 'Estudiante aleatorio';
$string['minpercent'] = 'Porcentaje mínimo';
$string['movedown'] = 'Bajar';
$string['moveup'] = 'Subir';
$string['newreference'] = 'Nueva referencia';
$string['noactivecase'] = 'Todavía no hay un caso activo cargado.';
$string['nohistory'] = 'Todavía no hay elementos guardados.';
$string['noairesult'] = 'Todavía no se ha generado un resultado IA.';
$string['pendingresult'] = 'Resultado simulado sin guardar';
$string['percent'] = 'Porcentaje';
$string['promptpreview'] = 'Vista previa del payload de evaluación';
$string['savednotice'] = 'Guardado en el historial temporal de la página.';
$string['simulated'] = 'Simulado';
$string['student'] = 'Estudiante';
$string['testkept'] = 'Prueba IA conservada en el historial.';
$string['weight'] = 'Peso';

$string['settings:mode'] = 'Modo de funcionamiento';
$string['settings:mode_desc'] = 'Mock usa respuestas simuladas. External envía la prueba IA al webhook configurado desde Moodle.';
$string['settings:mode_mock'] = 'mock';
$string['settings:mode_external'] = 'external';
$string['settings:service_url'] = 'URL base del servicio externo';
$string['settings:service_url_desc'] = 'URL del webhook n8n o backend externo que recibirá el JSON de evaluación.';
$string['settings:service_token'] = 'API Key o token';
$string['settings:service_token_desc'] = 'Token sensible usado para autenticar las peticiones al servicio externo. No se expone al navegador.';
$string['settings:timeout'] = 'Timeout de petición';
$string['settings:timeout_desc'] = 'Tiempo máximo, en segundos, para esperar la respuesta del servicio externo.';

$string['aitestdeleted'] = 'Prueba IA eliminada.';
$string['aitestsaved'] = 'Prueba IA guardada.';
$string['configsaved'] = 'Configuración guardada.';
$string['configsavedweightwarning'] = 'Configuración guardada. Advertencia: los pesos suman {$a}%, no 100%.';
$string['criterionnamerequired'] = 'Cada criterio necesita un nombre.';
$string['criterionwithoutlevels'] = 'Cada criterio necesita al menos un nivel.';
$string['externalfallbackmissingconfig'] = 'El modo external está activo, pero falta URL o token. Se generó una prueba segura en modo mock.';
$string['externalinvalidjson'] = 'El servicio externo no devolvió JSON válido.';
$string['externalinvalidlevel'] = 'El servicio externo devolvió un criterio o nivel que no pertenece a esta rúbrica.';
$string['externalinvalidresponse'] = 'El servicio externo devolvió una respuesta incompleta.';
$string['externalmissingcriterion'] = 'El servicio externo no devolvió detalle para todos los criterios.';
$string['externalrequestfailed'] = 'Falló la petición externa (HTTP {$a->httpcode}). {$a->detail}';
$string['externalresultnotice'] = 'Prueba IA recibida desde el servicio externo y guardada.';
$string['invalidcriteria'] = 'La estructura de criterios no es válida.';
$string['invalidcourse'] = 'La configuración no pertenece al curso actual.';
$string['invalidjson'] = 'La petición enviada al backend no contiene JSON válido.';
$string['invalidlevelselection'] = 'El nivel seleccionado no pertenece al criterio correspondiente.';
$string['invalidnumber'] = 'Uno de los valores numéricos no es válido.';
$string['invalidpayload'] = 'No se pudo construir el payload para el servicio externo.';
$string['invalidstudentselection'] = 'Solo se pueden seleccionar estudiantes del curso para esta prueba.';
$string['levelnamerequired'] = 'Cada nivel necesita un nombre.';
$string['levelsrequired'] = 'Cada criterio necesita al menos un nivel.';
$string['manualdeleted'] = 'Calificación manual eliminada.';
$string['manualincomplete'] = 'Debes seleccionar un nivel para cada criterio.';
$string['manualmaxreferences'] = 'Solo puedes guardar hasta 3 calificaciones manuales de referencia.';
$string['manualsaved'] = 'Calificación manual guardada.';
$string['manualupdated'] = 'Calificación manual actualizada.';
$string['mockcriteriadetail'] = '{$a->criterion}: nivel "{$a->level}" ({$a->percentage}%). Respuesta simulada para validar el flujo antes de conectar el servicio externo.';
$string['mockgeneralfeedback'] = 'Respuesta simulada: la entrega fue evaluada usando los niveles configurados en la rúbrica.';
$string['mockresultnotice'] = 'Prueba IA simulada y guardada.';
$string['nocriteria'] = 'Define al menos un criterio de evaluación.';
$string['resultnotready'] = 'El resultado todavía no está listo para publicar.';
$string['resultpublished'] = 'Resultado publicado.';
$string['resultsaved'] = 'Resultado revisado guardado.';
$string['unexpectederror'] = 'Ocurrió un error inesperado al procesar la solicitud.';
$string['unknownaction'] = 'Acción AJAX no reconocida.';

// Tercera interfaz: retroalimentación del estudiante.
$string['ai_grading:viewfeedback'] = 'Ver mi retroalimentación de AI Grading';

$string['assistant:title'] = 'Asistente de retroalimentación';
$string['assistant:open'] = 'Abrir asistente de retroalimentación';
$string['assistant:close'] = 'Cerrar';
$string['assistant:greeting'] = 'Hola {$a},';
$string['assistant:intro'] = 'Tu docente publicó retroalimentación generada con IA para ayudarte a mejorar tus próximas entregas.';
$string['assistant:current'] = 'Esta actividad';
$string['assistant:viewfeedback'] = 'Ver retroalimentación';

$string['feedback:pagetitle'] = 'Mi retroalimentación';
$string['feedback:activitylabel'] = 'Actividad VPL';
$string['feedback:summary'] = 'Resumen';
$string['feedback:grade'] = 'Nota';
$string['feedback:submitted'] = 'Fecha de envío';
$string['feedback:published'] = 'Fecha de publicación';
$string['feedback:reviewedby'] = 'Revisado por';
$string['feedbackreviewedbyvalue'] = 'IA + docente';
$string['feedback:criteria'] = 'Desglose por criterios';
$string['feedback:criterion'] = 'Criterio';
$string['feedback:level'] = 'Nivel alcanzado';
$string['feedback:score'] = 'Puntaje';
$string['feedback:comment'] = 'Comentario';
$string['feedback:total'] = 'Total';
$string['feedback:general'] = 'Retroalimentación general';
$string['feedback:nogeneral'] = 'Tu docente no dejó un comentario general adicional.';
$string['feedback:nocomment'] = 'Sin comentario';
$string['feedback:mysubmission'] = 'Mi entrega';
$string['feedback:tabcode'] = 'Código enviado';
$string['feedback:taboutput'] = 'Salida y resultados';
$string['feedback:nocode'] = 'No se pudo cargar el código de tu entrega.';
$string['feedback:nooutput'] = 'No hay salida de ejecución disponible.';
$string['feedback:notpublished'] = 'Aún no tienes retroalimentación publicada para esta actividad.';
$string['feedback:backtoactivity'] = 'Volver a la actividad';
