define(['core/ajax'], function(Ajax) {
    const rootSelector = 'local-ai-grading-app';

    let root = null;
    let state = null;
    let idCounter = 0;

    const rubricDefaults = [
        {
            id: 'tmp-correctness',
            name: 'Correctitud',
            weight: 40,
            description: 'El código produce los resultados esperados',
            levels: [
                {id: 'tmp-correctness-1', name: 'Excelente', percentage: 100, description: 'Pasa todos los casos de prueba'},
                {id: 'tmp-correctness-2', name: 'Aceptable', percentage: 50, description: 'Pasa algunos casos de prueba'},
                {id: 'tmp-correctness-3', name: 'Insuficiente', percentage: 0, description: 'No pasa casos de prueba'}
            ]
        },
        {
            id: 'tmp-efficiency',
            name: 'Eficiencia',
            weight: 30,
            description: 'Uso adecuado de recursos y estructuras',
            levels: [
                {id: 'tmp-efficiency-1', name: 'Excelente', percentage: 100, description: 'Solución eficiente y clara'},
                {id: 'tmp-efficiency-2', name: 'Aceptable', percentage: 50, description: 'Solución funcional con oportunidades de mejora'},
                {id: 'tmp-efficiency-3', name: 'Insuficiente', percentage: 0, description: 'Solución ineficiente o incompleta'}
            ]
        },
        {
            id: 'tmp-style',
            name: 'Estilo',
            weight: 20,
            description: 'Claridad, legibilidad y organización del código',
            levels: [
                {id: 'tmp-style-1', name: 'Excelente', percentage: 100, description: 'Código legible, organizado y mantenible'},
                {id: 'tmp-style-2', name: 'Aceptable', percentage: 50, description: 'Código entendible con detalles por mejorar'},
                {id: 'tmp-style-3', name: 'Insuficiente', percentage: 0, description: 'Código difícil de leer o mantener'}
            ]
        },
        {
            id: 'tmp-docs',
            name: 'Documentación',
            weight: 10,
            description: 'Comentarios y explicaciones apropiadas',
            levels: [
                {id: 'tmp-docs-1', name: 'Excelente', percentage: 100, description: 'Documentación clara y suficiente'},
                {id: 'tmp-docs-2', name: 'Aceptable', percentage: 50, description: 'Documentación básica'},
                {id: 'tmp-docs-3', name: 'Insuficiente', percentage: 0, description: 'Sin documentación relevante'}
            ]
        }
    ];

    const init = () => {
        root = document.getElementById(rootSelector);
        if (!root || root.dataset.aiGradingReady === '1') {
            return;
        }

        root.dataset.aiGradingReady = '1';
        state = createState();
        root.addEventListener('click', event => {
            void handleClick(event);
        });
        root.addEventListener('input', handleInput);
        root.addEventListener('change', event => {
            void handleChange(event);
        });
        renderLoading();
        void loadState(0);
    };

    const createState = () => ({
        courseid: Number(root.dataset.courseid || 0),
        view: 'setup',
        settings: {mode: 'mock', externalConfigured: false, timeout: 30},
        activities: [],
        selectedActivity: null,
        selectedVPL: '',
        config: null,
        rubricCriteria: clone(rubricDefaults),
        editablePrompt: '',
        promptDirty: false,
        promptLocked: true,
        students: [],
        manuals: [],
        aiTests: [],
        submissions: {},
        loadingSubmissions: {},
        loading: true,
        savingConfig: false,
        savingManual: false,
        isGenerating: false,
        manualEvalType: null,
        manualEvalStudent: '',
        manualSubmission: '',
        manualEditingId: '',
        manualLevels: {},
        manualFeedback: {},
        manualObservations: '',
        testCodeSource: null,
        selectedTestStudent: '',
        testSubmission: '',
        randomStudentId: '',
        latestAiResult: null,
        resultsState: null,
        results: [],
        resultSummary: {total: 0, pending: 0, processing: 0, evaluated: 0, error: 0, published: 0},
        selectedResults: [],
        resultSearchTerm: '',
        resultStatusFilter: 'all',
        publicationFilter: 'all',
        bulkRunning: false,
        activeResult: null,
        resultDrawerMode: null,
        resultDraft: {finaltotalgrade: '', finalfeedback: '', studentfeedback: ''},
        savingResult: false,
    });

    const loadState = async(vplid) => {
        state.loading = true;
        renderAll();

        try {
            const data = await request('get_state', {vplid: Number(vplid || 0)});
            applyState(data);
            renderAll();
        } catch (error) {
            state.loading = false;
            renderAll();
            showToast(error.message || 'No se pudo cargar AI Grading.');
        }
    };

    const applyState = data => {
        state.loading = false;
        state.settings = data.settings || state.settings;
        state.activities = data.activities || [];
        state.selectedActivity = data.selectedActivity || null;
        state.selectedVPL = state.selectedActivity ? String(state.selectedActivity.id) : '';
        state.config = data.config || null;
        state.students = data.students || [];
        state.manuals = data.manuals || [];
        state.aiTests = data.aiTests || [];
        state.latestAiResult = null;

        if (data.criteria && data.criteria.length) {
            state.rubricCriteria = normaliseCriteria(data.criteria);
        } else {
            state.rubricCriteria = clone(rubricDefaults);
        }

        state.promptDirty = false;
        state.promptLocked = true;
        state.editablePrompt = generateFullPrompt();
        resetSelections();
    };

    const resetSelections = () => {
        state.manualEvalType = null;
        state.manualEvalStudent = '';
        state.manualSubmission = '';
        state.manualEditingId = '';
        state.manualLevels = {};
        state.manualFeedback = {};
        state.manualObservations = '';
        state.testCodeSource = null;
        state.selectedTestStudent = '';
        state.testSubmission = '';
        state.randomStudentId = '';
    };

    const renderLoading = () => {
        region('activity-card').innerHTML = '<div class="ag-empty">Cargando datos de Moodle...</div>';
        region('rubric-card').innerHTML = '';
        region('manual-card').innerHTML = '';
        region('ai-test-card').innerHTML = '';
        region('prompt-card').innerHTML = '';
        region('main-action').innerHTML = '';
    };

    const clearMainRegions = () => {
        region('activity-card').innerHTML = '';
        region('rubric-card').innerHTML = '';
        region('manual-card').innerHTML = '';
        region('ai-test-card').innerHTML = '';
        region('prompt-card').innerHTML = '';
        region('main-action').innerHTML = '';
    };

    const renderAll = () => {
        root.classList.toggle('is-results-view', state.view === 'results');
        if (state.loading) {
            renderLoading();
            return;
        }
        if (state.view === 'results') {
            renderResultsView();
            return;
        }
        syncPromptIfNeeded();
        renderActivityCard();
        renderRubricCard();
        renderManualCard();
        renderAiTestCard();
        renderPromptCard();
        renderMainAction();
    };

    const renderActivityCard = () => {
        region('activity-card').innerHTML = `
            <h3 class="ag-card-title">Actividad VPL</h3>
            <div class="ag-stack">
                <div class="ag-field">
                    <label for="ag-vpl-select">Selecciona la actividad</label>
                    <select id="ag-vpl-select" class="ag-select" data-action="select-vpl">
                        <option value="">Selecciona una actividad VPL</option>
                        ${state.activities.map(activity => `
                            <option value="${activity.id}" ${String(activity.id) === state.selectedVPL ? 'selected' : ''}>
                                ${escapeHtml(activity.name)}
                            </option>
                        `).join('')}
                    </select>
                    ${!state.activities.length ? `
                        <div class="ag-info-box ag-info-box--yellow">No se encontraron actividades VPL en este curso.</div>
                    ` : ''}
                    ${state.selectedActivity ? `
                        <p class="ag-success-line">Actividad seleccionada. ${state.config ? 'Configuración cargada desde Moodle.' : 'Todavía no tiene configuración guardada.'}</p>
                    ` : ''}
                </div>
                ${state.selectedActivity ? `
                    <div class="ag-activity-preview">
                        <strong>${escapeHtml(state.selectedActivity.name)}</strong>
                        <p>${escapeHtml(state.selectedActivity.description || 'Sin descripción disponible.')}</p>
                        <span>${state.students.length} estudiante(s) con entregas registradas</span>
                    </div>
                ` : ''}
            </div>
        `;
    };

    const renderRubricCard = () => {
        const total = totalWeight();
        region('rubric-card').innerHTML = `
            <div class="ag-card-header">
                <h3 class="ag-card-title">Criterios de Evaluación</h3>
                <button type="button" class="ag-btn ag-btn--outline ag-btn--sm" data-action="add-criterion"
                    ${state.selectedVPL ? '' : 'disabled'}>
                    <span class="ag-icon">+</span>
                    Añadir criterio
                </button>
            </div>

            <div class="ag-criteria-stack">
                ${state.rubricCriteria.map(renderCriterion).join('')}
            </div>

            <div class="ag-total ${total === 100 ? 'is-ok' : 'is-error'}">
                Total: ${formatNumber(total)}% ${total !== 100 ? '(debe sumar 100%)' : ''}
            </div>
        `;
    };

    const renderCriterion = criterion => `
        <article class="ag-criterion" data-criterion-id="${escapeAttr(criterion.id)}">
            <div class="ag-criterion-header">
                <input class="ag-input ag-input--strong" type="text" value="${escapeAttr(criterion.name)}"
                    placeholder="Nombre del criterio" data-action="criterion-field" data-field="name"
                    data-criterion-id="${escapeAttr(criterion.id)}" ${state.selectedVPL ? '' : 'disabled'}>
                <div class="ag-weight-field">
                    <input class="ag-input" type="number" min="0" max="100" step="0.01" value="${criterion.weight}"
                        placeholder="Peso" data-action="criterion-field" data-field="weight"
                        data-criterion-id="${escapeAttr(criterion.id)}" ${state.selectedVPL ? '' : 'disabled'}>
                    <span>%</span>
                </div>
                <button type="button" class="ag-icon-btn ag-icon-btn--danger" data-action="remove-criterion"
                    data-criterion-id="${escapeAttr(criterion.id)}"
                    ${state.rubricCriteria.length <= 1 || !state.selectedVPL ? 'disabled' : ''} aria-label="Eliminar criterio">
                    <span aria-hidden="true">x</span>
                </button>
            </div>

            <textarea class="ag-textarea ag-textarea--sm" rows="2" placeholder="Descripción del criterio"
                data-action="criterion-field" data-field="description"
                data-criterion-id="${escapeAttr(criterion.id)}" ${state.selectedVPL ? '' : 'disabled'}>${escapeHtml(criterion.description)}</textarea>

            <div class="ag-level-table">
                <div class="ag-level-table-header">
                    <span>Tabla de Niveles</span>
                    <button type="button" class="ag-btn ag-btn--ghost ag-btn--xs" data-action="add-level"
                        data-criterion-id="${escapeAttr(criterion.id)}" ${state.selectedVPL ? '' : 'disabled'}>
                        <span class="ag-icon">+</span>
                        Añadir nivel
                    </button>
                </div>
                <div class="ag-level-list">
                    ${criterion.levels.map(level => renderLevel(criterion, level)).join('')}
                </div>
            </div>
        </article>
    `;

    const renderLevel = (criterion, level) => `
        <div class="ag-level-row" data-level-id="${escapeAttr(level.id)}">
            <input class="ag-input ag-level-percent" type="number" min="0" max="100" step="0.01" value="${level.percentage}"
                placeholder="0" data-action="level-field" data-field="percentage"
                data-criterion-id="${escapeAttr(criterion.id)}" data-level-id="${escapeAttr(level.id)}">
            <span class="ag-percent-mark">%</span>
            <input class="ag-input" type="text" value="${escapeAttr(level.name)}"
                placeholder="Nombre del nivel" data-action="level-field" data-field="name"
                data-criterion-id="${escapeAttr(criterion.id)}" data-level-id="${escapeAttr(level.id)}">
            <input class="ag-input" type="text" value="${escapeAttr(level.description)}"
                placeholder="Descripción del nivel" data-action="level-field" data-field="description"
                data-criterion-id="${escapeAttr(criterion.id)}" data-level-id="${escapeAttr(level.id)}">
            <button type="button" class="ag-icon-btn ag-icon-btn--danger" data-action="remove-level"
                data-criterion-id="${escapeAttr(criterion.id)}" data-level-id="${escapeAttr(level.id)}"
                ${criterion.levels.length <= 1 ? 'disabled' : ''} aria-label="Eliminar nivel">
                <span aria-hidden="true">x</span>
            </button>
        </div>
    `;

    const renderManualCard = () => {
        const canEditReferences = state.manualEditingId || state.manuals.length < 3;
        region('manual-card').innerHTML = `
            <div class="ag-title-row">
                <h3 class="ag-card-title">Evaluación Manual del Profesor</h3>
                <span class="ag-badge ag-badge--amber">${state.manuals.length}/3 referencias</span>
            </div>
            <p class="ag-muted">
                Guarda hasta 3 calificaciones de referencia para calibrar cómo debe evaluar la IA.
            </p>

            ${canEditReferences ? `
                ${state.manualEditingId ? `
                    <div class="ag-info-box ag-info-box--yellow">Editando referencia manual #${state.manualEditingId}.</div>
                ` : ''}
                <div class="ag-choice-grid">
                    ${choiceButton('manual-specific', 'specific', state.manualEvalType, 'Estudiante específico', 'Elige un estudiante', 'manual-type')}
                    ${choiceButton('manual-random', 'random', state.manualEvalType, 'Estudiante aleatorio', 'Selección al azar', 'manual-type', true)}
                </div>

                ${state.manualEvalType === 'specific' ? studentSelect('manual-student-select', state.manualEvalStudent, 'Elige un estudiante', 'manual-student') : ''}
                ${state.manualEvalType === 'random' && state.manualEvalStudent ? selectedStudentMessage(state.manualEvalStudent, 'green', 'Estudiante') : ''}
                ${state.manualEvalStudent ? submissionSelect('manual-submission-select', state.manualEvalStudent, state.manualSubmission, 'manual-submission') : ''}
                ${state.manualEvalType && state.manualEvalStudent && state.manualSubmission ? renderManualEvaluationWorkspace() : ''}
            ` : `
                <div class="ag-info-box ag-info-box--yellow">Ya tienes 3 referencias manuales. Elimina o edita una para cambiar la calibración.</div>
            `}
            ${renderManualHistory()}
        `;
    };

    const renderManualEvaluationWorkspace = () => `
        <div class="ag-manual-workspace">
            ${submissionPreview(state.manualSubmission)}
            <p class="ag-section-label">Califica cada criterio seleccionando un nivel:</p>
            ${state.rubricCriteria.map(criterion => `
                <div class="ag-manual-criterion">
                    <label>${escapeHtml(criterion.name)} (${formatNumber(criterion.weight)} puntos)</label>
                    <select class="ag-select" data-action="manual-level" data-criterion-id="${escapeAttr(criterion.id)}">
                        <option value="">Selecciona un nivel</option>
                        ${criterion.levels.map(level => `
                            <option value="${escapeAttr(level.id)}" ${String(state.manualLevels[criterion.id] || '') === String(level.id) ? 'selected' : ''}>
                                ${escapeHtml(level.name)} - ${formatNumber(level.percentage)}%
                            </option>
                        `).join('')}
                    </select>
                    <textarea class="ag-textarea ag-textarea--sm" rows="2" placeholder="Observación para este criterio..."
                        data-action="manual-feedback" data-criterion-id="${escapeAttr(criterion.id)}">${escapeHtml(state.manualFeedback[criterion.id] || '')}</textarea>
                </div>
            `).join('')}
            <textarea class="ag-textarea ag-textarea--sm" rows="3" placeholder="Observaciones generales..."
                data-action="manual-observations">${escapeHtml(state.manualObservations)}</textarea>
            <div class="ag-manual-total">
                <p>Calificación Total:</p>
                <strong>${formatNumber(manualTotal())}/100</strong>
            </div>
            <button type="button" class="ag-btn ag-btn--primary ag-btn--full" data-action="save-manual"
                ${isManualComplete() && !state.savingManual ? '' : 'disabled'}>
                ${state.savingManual ? 'Guardando...' : (state.manualEditingId ? 'Actualizar referencia manual' : 'Guardar referencia manual')}
            </button>
            ${state.manualEditingId ? `
                <button type="button" class="ag-btn ag-btn--outline ag-btn--full" data-action="cancel-manual-edit">
                    Cancelar edición
                </button>
            ` : ''}
        </div>
    `;

    const renderManualHistory = () => `
        <div class="ag-history-block">
            <div class="ag-history-heading">
                <strong>Calificaciones manuales guardadas</strong>
                <span>${state.manuals.length}</span>
            </div>
            ${state.manuals.length ? `
                <div class="ag-history-list">
                    ${state.manuals.map(item => `
                        <div class="ag-history-item ${String(state.manualEditingId) === String(item.id) ? 'is-editing' : ''}">
                            <div>
                                <strong>${escapeHtml(item.studentName)}</strong>
                                <span>Entrega #${item.submissionid} · ${escapeHtml(item.timecreatedText)} · ${formatNumber(item.totalgrade)}/100</span>
                            </div>
                            <div class="ag-history-actions">
                                <button type="button" class="ag-btn ag-btn--ghost ag-btn--xs" data-action="edit-manual"
                                    data-manual-id="${item.id}">Editar</button>
                                <button type="button" class="ag-btn ag-btn--ghost ag-btn--xs" data-action="delete-manual"
                                    data-manual-id="${item.id}">Eliminar</button>
                            </div>
                        </div>
                    `).join('')}
                </div>
            ` : '<div class="ag-empty ag-empty--compact">Todavía no hay calificaciones manuales guardadas.</div>'}
        </div>
    `;

    const renderAiTestCard = () => {
        const modeLabel = state.settings.mode === 'external'
            ? (state.settings.externalConfigured ? 'External configurado' : 'External sin URL/token: usará mock seguro')
            : 'Mock';

        region('ai-test-card').innerHTML = `
            <div class="ag-card-header">
                <h3 class="ag-card-title">Evaluación de prueba con IA</h3>
                <span class="ag-badge">${escapeHtml(modeLabel)}</span>
            </div>
            <p class="ag-muted">Prueba una entrega real antes de continuar con fases posteriores.</p>

            <div class="ag-choice-grid">
                ${choiceButton('test-student', 'student', state.testCodeSource, 'Estudiante específico', 'Elige un estudiante del curso', 'test-source')}
                ${choiceButton('test-random', 'random', state.testCodeSource, 'Estudiante aleatorio', 'Selecciona una entrega al azar', 'test-source', true)}
            </div>

            ${state.testCodeSource === 'student' ? studentSelect('student-select', state.selectedTestStudent, 'Elige un estudiante del curso', 'test-student') : ''}
            ${state.testCodeSource === 'random' && state.randomStudentId ? selectedStudentMessage(state.randomStudentId, 'blue', 'Estudiante seleccionado') : ''}
            ${selectedTestStudentId() ? submissionSelect('test-submission-select', selectedTestStudentId(), state.testSubmission, 'test-submission') : ''}
            ${renderTestCodePreview()}
            ${state.testCodeSource ? `
                <button type="button" class="ag-btn ag-btn--primary ag-btn--full ag-btn--lg" data-action="generate-preview"
                    ${canGeneratePreview() ? '' : 'disabled'}>
                    <span class="ag-play-icon" aria-hidden="true"></span>
                    ${state.isGenerating ? 'Evaluando...' : 'Probar evaluación con IA'}
                </button>
            ` : ''}
            ${state.isGenerating ? `
                <div class="ag-ai-pending">
                    <strong>Analizando entrega con IA</strong>
                    <span>Revisando código, salida de ejecución y rúbrica configurada.</span>
                    <div aria-hidden="true"></div>
                </div>
            ` : ''}
            ${renderAiResults()}
            ${renderAiHistory()}
        `;
    };

    const renderPromptCard = () => {
        const locked = state.promptLocked;
        const lockLabel = locked ? 'Desbloquear edición manual' : 'Bloquear y regenerar automáticamente';
        const lockIcon = locked ? '&#128274;' : '&#128275;';
        region('prompt-card').innerHTML = `
            <div class="ag-prompt-heading">
                <div class="ag-prompt-title">
                    <span class="ag-settings-icon" aria-hidden="true"></span>
                    <h3 class="ag-card-title">Prompt de evaluación</h3>
                </div>
                <button type="button" class="ag-icon-btn ag-prompt-lock ${locked ? 'is-locked' : 'is-unlocked'}"
                    data-action="toggle-prompt-lock" title="${lockLabel}" aria-label="${lockLabel}">
                    <span aria-hidden="true">${lockIcon}</span>
                </button>
            </div>
            <p class="ag-prompt-help">
                ${locked
                    ? 'Vista sincronizada con la actividad y la rúbrica. Desbloquea el candado para editar manualmente.'
                    : 'Edición manual activa. Al bloquear de nuevo se regenerará con la actividad y la rúbrica actuales.'}
            </p>
            <textarea class="ag-textarea ag-prompt-textarea ${locked ? 'is-locked' : 'is-unlocked'}" rows="20"
                data-action="prompt-input" ${locked ? 'readonly' : ''}
                placeholder="El prompt se generará automáticamente al seleccionar una actividad VPL y configurar los criterios...">${escapeHtml(promptValue())}</textarea>
            ${totalWeight() !== 100 ? `
                <div class="ag-info-box ag-info-box--yellow">Los pesos de la rúbrica suman ${formatNumber(totalWeight())}%. Puedes guardar, pero conviene ajustarlos a 100%.</div>
            ` : ''}
            ${!state.selectedVPL ? `
                <div class="ag-info-box ag-info-box--gray">Selecciona una actividad VPL para comenzar.</div>
            ` : ''}
            ${state.config ? `
                <div class="ag-info-box ag-info-box--green">Configuración Moodle #${state.config.id} cargada.</div>
            ` : ''}
        `;
    };

    const renderMainAction = () => {
        const disabled = !state.selectedVPL || state.savingConfig;
        region('main-action').innerHTML = `
            <div class="ag-action-center">
                <button type="button" class="ag-btn ag-btn--primary ag-btn--continue" data-action="save-continue"
                    ${disabled ? 'disabled' : ''}>
                    ${state.savingConfig ? 'Guardando...' : 'Guardar y continuar a resultados'}
                </button>
                ${!state.selectedVPL ? `
                    <p class="ag-action-help">Selecciona una actividad VPL para continuar</p>
                ` : ''}
            </div>
        `;
    };

    const renderTestCodePreview = () => {
        if (!state.testSubmission) {
            return '';
        }
        return `<div class="ag-code-preview">${submissionPreview(state.testSubmission)}</div>`;
    };

    const renderAiResults = () => {
        const result = state.latestAiResult;
        if (!result) {
            return '';
        }
        const details = result.details || [];
        const total = aiResultTotal(result);
        const totalStyle = gradeStyle(total, 100);

        return `
            <div class="ag-ai-results">
                <div class="ag-ai-summary" style="${totalStyle}">
                    <strong>Evaluación completada y guardada</strong>
                    <span>${escapeHtml(result.studentName)} · ${formatNumber(total)}/100</span>
                </div>
                <div class="ag-grade-grid">
                    <div class="ag-grade-card" style="${totalStyle}">
                        <p>Nota sugerida por IA</p>
                        <strong>${formatNumber(total)}</strong>
                        <span>/100</span>
                    </div>
                    <div class="ag-breakdown-card">
                        <p>Desglose por criterio</p>
                        ${details.map(item => `
                            <div class="ag-breakdown-row" style="${gradeStyle(item.score, item.max)}">
                                <div>
                                    <span>${escapeHtml(item.criterionName)}</span>
                                    <small>${escapeHtml(item.levelName)} · ${formatNumber(gradePercent(item.score, item.max))}%</small>
                                </div>
                                <strong>${formatNumber(item.score)}/${formatNumber(item.max)}</strong>
                            </div>
                        `).join('')}
                    </div>
                </div>
                <div class="ag-feedback-box">
                    <label>Feedback generado por IA</label>
                    <div>${escapeHtml(result.generalfeedback || 'Sin retroalimentación general.')}</div>
                </div>
                ${details.map(item => `
                    <div class="ag-feedback-box ag-feedback-box--criterion" style="${gradeStyle(item.score, item.max)}">
                        <label>${escapeHtml(item.criterionName)}</label>
                        <div>${escapeHtml(item.detail || 'Sin detalle.')}</div>
                    </div>
                `).join('')}
            </div>
        `;
    };

    const renderAiHistory = () => `
        <div class="ag-history-block">
            <div class="ag-history-heading">
                <strong>Pruebas IA guardadas</strong>
                <span>${state.aiTests.length}</span>
            </div>
            ${state.aiTests.length ? `
                <div class="ag-history-list">
                    ${state.aiTests.map(item => `
                        <div class="ag-history-item">
                            <div>
                                <strong>${escapeHtml(item.studentName)}</strong>
                                <span>Entrega #${item.submissionid} · ${escapeHtml(item.timereceivedText)} · ${formatNumber(item.totalgrade)}/100</span>
                            </div>
                            <button type="button" class="ag-btn ag-btn--ghost ag-btn--xs" data-action="delete-ai-test"
                                data-test-id="${item.id}">Eliminar</button>
                        </div>
                    `).join('')}
                </div>
            ` : '<div class="ag-empty ag-empty--compact">Todavía no hay pruebas IA guardadas.</div>'}
        </div>
    `;

    const renderResultsView = () => {
        root.classList.add('is-results-view');
        clearMainRegions();
        region('activity-card').innerHTML = renderResultsContext();
        region('rubric-card').innerHTML = renderResultsTable();
        region('prompt-card').innerHTML = '';
        renderResultDrawer();
    };

    const renderResultsContext = () => {
        const summary = state.resultSummary || {};
        const activity = state.resultsState ? state.resultsState.activity : state.selectedActivity;
        return `
            <div class="ag-results-heading">
                <div>
                    <h3 class="ag-card-title">Resultados de evaluación IA</h3>
                    <p class="ag-muted">Revisa, ajusta y publica calificaciones generadas con la configuración aprobada.</p>
                </div>
                <button type="button" class="ag-btn ag-btn--outline ag-btn--sm" data-action="back-to-setup">
                    Volver a configuración
                </button>
            </div>
            <div class="ag-results-context">
                <div>
                    <span>Actividad VPL</span>
                    <strong>${escapeHtml(activity ? activity.name : 'Actividad seleccionada')}</strong>
                    <small>${escapeHtml(activity && activity.description ? activity.description : 'Sin descripción disponible.')}</small>
                </div>
                <div class="ag-result-metrics">
                    ${resultMetric('Total', summary.total || 0)}
                    ${resultMetric('Evaluados', summary.evaluated || 0)}
                    ${resultMetric('Pendientes', (summary.pending || 0) + (summary.processing || 0))}
                    ${resultMetric('Publicados', summary.published || 0)}
                </div>
            </div>
        `;
    };

    const renderResultsTable = () => {
        const filtered = filteredResults();
        return `
            <div class="ag-results-toolbar">
                <div>
                    <h3 class="ag-card-title">Gestión de estudiantes</h3>
                    <p class="ag-muted">La evaluación masiva procesa una entrega a la vez usando el mismo webhook externo.</p>
                </div>
                <button type="button" class="ag-btn ag-btn--primary ag-btn--sm" data-action="run-selected-results"
                    ${selectedRunnableResults().length && !state.bulkRunning ? '' : 'disabled'}>
                    ${state.bulkRunning ? 'Evaluando...' : `Evaluar seleccionados (${selectedRunnableResults().length})`}
                </button>
            </div>
            <div class="ag-result-filters">
                <input class="ag-input" type="text" placeholder="Buscar estudiante o usuario..."
                    value="${escapeAttr(state.resultSearchTerm)}" data-action="result-search">
                <select class="ag-select" data-action="result-status-filter">
                    <option value="all" ${state.resultStatusFilter === 'all' ? 'selected' : ''}>Todos los estados IA</option>
                    <option value="pending" ${state.resultStatusFilter === 'pending' ? 'selected' : ''}>Pendiente</option>
                    <option value="processing" ${state.resultStatusFilter === 'processing' ? 'selected' : ''}>En proceso</option>
                    <option value="evaluated" ${state.resultStatusFilter === 'evaluated' ? 'selected' : ''}>Evaluado</option>
                    <option value="error" ${state.resultStatusFilter === 'error' ? 'selected' : ''}>Error</option>
                </select>
                <select class="ag-select" data-action="publication-filter">
                    <option value="all" ${state.publicationFilter === 'all' ? 'selected' : ''}>Toda publicación</option>
                    <option value="published" ${state.publicationFilter === 'published' ? 'selected' : ''}>Publicado</option>
                    <option value="not_published" ${state.publicationFilter === 'not_published' ? 'selected' : ''}>No publicado</option>
                </select>
            </div>
            ${state.bulkRunning ? `
                <div class="ag-ai-pending">
                    <strong>Evaluación masiva en curso</strong>
                    <span>Procesando estudiantes seleccionados uno por uno.</span>
                    <div aria-hidden="true"></div>
                </div>
            ` : ''}
            ${filtered.length ? `
                <div class="ag-results-table-wrap">
                    <table class="ag-results-table">
                        <thead>
                            <tr>
                                <th>
                                    <input type="checkbox" data-action="toggle-all-results"
                                        ${state.selectedResults.length === filtered.length && filtered.length ? 'checked' : ''}>
                                </th>
                                <th>Estudiante y entrega</th>
                                <th>Feedback IA</th>
                                <th>Estado IA</th>
                                <th>Nota IA</th>
                                <th>Nota final</th>
                                <th>Publicación</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${filtered.map(renderResultRow).join('')}
                        </tbody>
                    </table>
                </div>
            ` : '<div class="ag-empty">No hay estudiantes que coincidan con los filtros.</div>'}
            ${state.selectedResults.length ? `
                <div class="ag-batch-bar">
                    <span><strong>${state.selectedResults.length}</strong> resultado(s) seleccionado(s)</span>
                    <div>
                        <button type="button" class="ag-btn ag-btn--outline ag-btn--sm" data-action="clear-result-selection">Limpiar</button>
                        <button type="button" class="ag-btn ag-btn--primary ag-btn--sm" data-action="publish-selected-results"
                            ${selectedPublishableResults().length ? '' : 'disabled'}>
                            Publicar listos (${selectedPublishableResults().length})
                        </button>
                    </div>
                </div>
            ` : ''}
        `;
    };

    const renderResultRow = result => `
        <tr>
            <td>
                <input type="checkbox" data-action="toggle-result" data-result-id="${result.id}"
                    ${state.selectedResults.includes(String(result.id)) ? 'checked' : ''}>
            </td>
            <td>
                <strong>${escapeHtml(result.studentName)}</strong>
                <span>${escapeHtml(result.studentUsername)} · intento #${result.attemptnumber} · ${escapeHtml(result.timesubmittedText)}</span>
            </td>
            <td>
                <button type="button" class="ag-result-feedback-preview" data-action="view-result" data-result-id="${result.id}">
                    ${escapeHtml(resultFeedbackPreview(result))}
                </button>
            </td>
            <td>${resultStatusBadge(result.aistatus)}</td>
            <td>${result.aitotalgrade === null ? '<span class="ag-muted-cell">-</span>' : `<strong>${formatNumber(result.aitotalgrade)}</strong>`}</td>
            <td>${result.finaltotalgrade === null ? '<span class="ag-muted-cell">-</span>' : `<strong>${formatNumber(result.finaltotalgrade)}</strong>`}</td>
            <td>${publicationBadge(result.publicationStatus)}</td>
            <td>
                <div class="ag-row-actions">
                    <button type="button" class="ag-icon-btn" data-action="view-result" data-result-id="${result.id}" title="Ver">V</button>
                    <button type="button" class="ag-icon-btn" data-action="edit-result" data-result-id="${result.id}" title="Editar">E</button>
                    <button type="button" class="ag-icon-btn" data-action="run-one-result" data-result-id="${result.id}" title="Evaluar IA"
                        ${state.bulkRunning ? 'disabled' : ''}>IA</button>
                    <button type="button" class="ag-icon-btn" data-action="publish-result" data-result-id="${result.id}" title="Publicar"
                        ${isPublishable(result) ? '' : 'disabled'}>✓</button>
                </div>
            </td>
        </tr>
    `;

    const renderResultsConfigSummary = () => {
        const config = state.resultsState ? state.resultsState.config : state.config;
        const criteria = state.resultsState ? state.resultsState.criteria : state.rubricCriteria;
        return `
            <div class="ag-prompt-heading">
                <div class="ag-prompt-title">
                    <span class="ag-settings-icon" aria-hidden="true"></span>
                    <h3 class="ag-card-title">Configuración usada</h3>
                </div>
            </div>
            <p class="ag-prompt-help">Estos criterios y directrices se reutilizan para cada estudiante evaluado.</p>
            <div class="ag-results-config">
                <strong>Prompt base</strong>
                <pre>${escapeHtml(config ? config.prompt : promptValue())}</pre>
                <strong>Criterios</strong>
                ${criteria.map(criterion => `
                    <div class="ag-config-criterion">
                        <span>${escapeHtml(criterion.name)} · ${formatNumber(criterion.weight)}%</span>
                        <small>${escapeHtml(criterion.description || 'Sin descripción')}</small>
                    </div>
                `).join('')}
            </div>
        `;
    };

    const renderResultDrawer = () => {
        const result = state.activeResult;
        if (!result) {
            region('main-action').innerHTML = '';
            return;
        }
        const editable = state.resultDrawerMode === 'edit';
        const submission = state.submissions[result.submissionid];
        region('main-action').innerHTML = `
            <div class="ag-drawer-backdrop" data-action="close-result-drawer"></div>
            <aside class="ag-result-drawer" role="dialog" aria-modal="true" aria-label="Resultado de ${escapeAttr(result.studentName)}">
                <div class="ag-drawer-header">
                    <div>
                        <h3>${escapeHtml(result.studentName)}</h3>
                        <span>${escapeHtml(result.studentUsername)} · entrega #${result.submissionid}</span>
                    </div>
                    <button type="button" class="ag-icon-btn" data-action="close-result-drawer" aria-label="Cerrar">x</button>
                </div>
                <div class="ag-drawer-body">
                    ${result.errordetail ? `<div class="ag-info-box ag-info-box--yellow">${escapeHtml(result.errordetail)}</div>` : ''}
                    <div class="ag-drawer-score" style="${gradeStyle(result.aitotalgrade || 0, 100)}">
                        <span>Nota IA</span>
                        <strong>${result.aitotalgrade === null ? '-' : formatNumber(result.aitotalgrade)}</strong>
                    </div>
                    <section>
                        <h4>Desglose por criterio</h4>
                        ${(result.details || []).length ? result.details.map(item => `
                            <div class="ag-breakdown-row" style="${gradeStyle(item.score || 0, item.max || 100)}">
                                <div>
                                    <span>${escapeHtml(item.criterionName)}</span>
                                    <small>${escapeHtml(item.levelName || 'Sin nivel')} · ${escapeHtml(item.detail || 'Sin detalle.')}</small>
                                </div>
                                <strong>${item.score === null ? '-' : formatNumber(item.score)}/${formatNumber(item.max)}</strong>
                            </div>
                        `).join('') : '<div class="ag-empty ag-empty--compact">Todavía no hay evaluación IA.</div>'}
                    </section>
                    <section>
                        <h4>Revisión docente</h4>
                        <label>Nota final</label>
                        <input class="ag-input" type="number" min="0" max="100" step="0.01" data-action="result-draft-field"
                            data-field="finaltotalgrade" value="${escapeAttr(state.resultDraft.finaltotalgrade)}" ${editable ? '' : 'readonly'}>
                        <label>Feedback final para estudiante</label>
                        <textarea class="ag-textarea ag-textarea--sm" rows="5" data-action="result-draft-field"
                            data-field="finalfeedback" ${editable ? '' : 'readonly'}>${escapeHtml(state.resultDraft.finalfeedback)}</textarea>
                        <label>Notas internas</label>
                        <textarea class="ag-textarea ag-textarea--sm" rows="3" data-action="result-draft-field"
                            data-field="studentfeedback" ${editable ? '' : 'readonly'}>${escapeHtml(state.resultDraft.studentfeedback)}</textarea>
                    </section>
                    <section>
                        <h4>Código y salida VPL</h4>
                        ${submission ? submissionPreview(result.submissionid) : '<div class="ag-empty ag-empty--compact">Cargando entrega...</div>'}
                    </section>
                </div>
                <div class="ag-drawer-footer">
                    <button type="button" class="ag-btn ag-btn--outline ag-btn--sm" data-action="close-result-drawer">Cerrar</button>
                    ${editable ? `
                        <button type="button" class="ag-btn ag-btn--primary ag-btn--sm" data-action="save-result-review"
                            ${state.savingResult ? 'disabled' : ''}>${state.savingResult ? 'Guardando...' : 'Guardar revisión'}</button>
                    ` : `
                        <button type="button" class="ag-btn ag-btn--outline ag-btn--sm" data-action="edit-result" data-result-id="${result.id}">Editar</button>
                    `}
                    <button type="button" class="ag-btn ag-btn--primary ag-btn--sm" data-action="publish-result" data-result-id="${result.id}"
                        ${isPublishable(result) ? '' : 'disabled'}>Publicar</button>
                </div>
            </aside>
        `;
    };

    const submissionPreview = submissionId => {
        if (!submissionId) {
            return '';
        }
        const cached = state.submissions[submissionId];
        if (state.loadingSubmissions[submissionId]) {
            return '<div class="ag-empty ag-empty--compact">Cargando entrega...</div>';
        }
        if (!cached) {
            return '<div class="ag-empty ag-empty--compact">Selecciona una entrega para cargar el código.</div>';
        }
        return codeBlock('Código del estudiante:', cached);
    };

    const handleClick = async event => {
        const trigger = event.target.closest('[data-action]');
        if (!trigger || !root.contains(trigger)) {
            return;
        }

        const action = trigger.dataset.action;

        if (action === 'add-criterion') {
            addCriterion();
            renderAll();
            return;
        }

        if (action === 'remove-criterion') {
            removeCriterion(trigger.dataset.criterionId);
            renderAll();
            return;
        }

        if (action === 'add-level') {
            addLevel(trigger.dataset.criterionId);
            renderAll();
            return;
        }

        if (action === 'remove-level') {
            removeLevel(trigger.dataset.criterionId, trigger.dataset.levelId);
            renderAll();
            return;
        }

        if (action === 'manual-type') {
            setManualType(trigger.dataset.value);
            renderManualCard();
            await loadSelectedSubmission('manual');
            return;
        }

        if (action === 'save-manual') {
            await saveManualEvaluation();
            return;
        }

        if (action === 'delete-manual') {
            await deleteManual(trigger.dataset.manualId);
            return;
        }

        if (action === 'edit-manual') {
            await editManual(trigger.dataset.manualId);
            return;
        }

        if (action === 'cancel-manual-edit') {
            resetManualDraft();
            renderManualCard();
            return;
        }

        if (action === 'test-source') {
            setTestSource(trigger.dataset.value);
            renderAiTestCard();
            await loadSelectedSubmission('test');
            return;
        }

        if (action === 'generate-preview') {
            await generatePreview();
            return;
        }

        if (action === 'delete-ai-test') {
            await deleteAiTest(trigger.dataset.testId);
            return;
        }

        if (action === 'toggle-prompt-lock') {
            togglePromptLock();
            renderPromptCard();
            return;
        }

        if (action === 'save-continue') {
            await openResultsView();
            return;
        }

        if (action === 'back-to-setup') {
            state.view = 'setup';
            state.activeResult = null;
            renderAll();
            return;
        }

        if (action === 'toggle-result') {
            toggleResult(trigger.dataset.resultId);
            renderResultsView();
            return;
        }

        if (action === 'toggle-all-results') {
            toggleAllResults();
            renderResultsView();
            return;
        }

        if (action === 'clear-result-selection') {
            state.selectedResults = [];
            renderResultsView();
            return;
        }

        if (action === 'run-selected-results') {
            await runSelectedResults();
            return;
        }

        if (action === 'run-one-result') {
            await runOneResult(trigger.dataset.resultId);
            return;
        }

        if (action === 'view-result') {
            await openResultDrawer(trigger.dataset.resultId, 'view');
            return;
        }

        if (action === 'edit-result') {
            await openResultDrawer(trigger.dataset.resultId, 'edit');
            return;
        }

        if (action === 'close-result-drawer') {
            state.activeResult = null;
            state.resultDrawerMode = null;
            renderResultDrawer();
            return;
        }

        if (action === 'save-result-review') {
            await saveResultReview();
            return;
        }

        if (action === 'publish-result') {
            await publishResult(trigger.dataset.resultId);
            return;
        }

        if (action === 'publish-selected-results') {
            await publishSelectedResults();
            return;
        }

    };

    const handleInput = event => {
        const target = event.target;
        const action = target.dataset.action;

        if (action === 'criterion-field') {
            const criterion = findCriterion(target.dataset.criterionId);
            if (!criterion) {
                return;
            }
            const field = target.dataset.field;
            criterion[field] = field === 'weight' ? numberInRange(target.value, 0, 100) : target.value;
            if (!state.promptDirty) {
                state.editablePrompt = generateFullPrompt();
            }
            renderPromptCard();
            renderMainAction();
            if (field === 'weight') {
                renderManualCard();
                renderAiTestCard();
            }
            updateTotalText();
            return;
        }

        if (action === 'level-field') {
            const criterion = findCriterion(target.dataset.criterionId);
            const level = criterion ? criterion.levels.find(item => String(item.id) === String(target.dataset.levelId)) : null;
            if (!level) {
                return;
            }
            const field = target.dataset.field;
            level[field] = field === 'percentage' ? numberInRange(target.value, 0, 100) : target.value;
            if (!state.promptDirty) {
                state.editablePrompt = generateFullPrompt();
                renderPromptCard();
            }
            if (field === 'percentage' || field === 'name') {
                updateManualTotal();
            }
            return;
        }

        if (action === 'prompt-input') {
            if (state.promptLocked) {
                return;
            }
            state.editablePrompt = target.value;
            state.promptDirty = true;
            return;
        }

        if (action === 'result-search') {
            state.resultSearchTerm = target.value;
            renderResultsView();
            return;
        }

        if (action === 'result-draft-field') {
            state.resultDraft[target.dataset.field] = target.value;
            return;
        }

        if (action === 'manual-feedback') {
            state.manualFeedback[target.dataset.criterionId] = target.value;
            return;
        }

        if (action === 'manual-observations') {
            state.manualObservations = target.value;
            return;
        }

    };

    const handleChange = async event => {
        const target = event.target;
        const action = target.dataset.action;

        if (action === 'select-vpl') {
            await loadState(target.value);
            return;
        }

        if (action === 'manual-student') {
            state.manualEvalStudent = target.value;
            state.manualSubmission = defaultSubmissionForStudent(target.value);
            state.manualLevels = {};
            state.manualFeedback = {};
            renderManualCard();
            await loadSelectedSubmission('manual');
            return;
        }

        if (action === 'manual-submission') {
            state.manualSubmission = target.value;
            renderManualCard();
            await loadSelectedSubmission('manual');
            return;
        }

        if (action === 'manual-level') {
            state.manualLevels[target.dataset.criterionId] = target.value;
            updateManualTotal();
            return;
        }

        if (action === 'test-student') {
            state.selectedTestStudent = target.value;
            state.testSubmission = defaultSubmissionForStudent(target.value);
            state.latestAiResult = null;
            renderAiTestCard();
            await loadSelectedSubmission('test');
            return;
        }

        if (action === 'test-submission') {
            state.testSubmission = target.value;
            state.latestAiResult = null;
            renderAiTestCard();
            await loadSelectedSubmission('test');
            return;
        }

        if (action === 'result-status-filter') {
            state.resultStatusFilter = target.value;
            state.selectedResults = [];
            renderResultsView();
            return;
        }

        if (action === 'publication-filter') {
            state.publicationFilter = target.value;
            state.selectedResults = [];
            renderResultsView();
        }
    };

    const addCriterion = () => {
        const newId = createId('criterion');
        state.rubricCriteria.push({
            id: newId,
            name: 'Nuevo criterio',
            weight: 0,
            description: '',
            levels: [
                {id: createId('level'), name: 'Excelente', percentage: 100, description: ''},
                {id: createId('level'), name: 'Aceptable', percentage: 50, description: ''},
                {id: createId('level'), name: 'Insuficiente', percentage: 0, description: ''}
            ]
        });
        markPromptGenerated();
    };

    const removeCriterion = criterionId => {
        if (state.rubricCriteria.length <= 1) {
            return;
        }
        state.rubricCriteria = state.rubricCriteria.filter(item => String(item.id) !== String(criterionId));
        delete state.manualLevels[criterionId];
        delete state.manualFeedback[criterionId];
        markPromptGenerated();
    };

    const addLevel = criterionId => {
        const criterion = findCriterion(criterionId);
        if (!criterion) {
            return;
        }
        criterion.levels.push({id: createId('level'), name: 'Nuevo nivel', percentage: 0, description: ''});
        markPromptGenerated();
    };

    const removeLevel = (criterionId, levelId) => {
        const criterion = findCriterion(criterionId);
        if (!criterion || criterion.levels.length <= 1) {
            return;
        }
        criterion.levels = criterion.levels.filter(item => String(item.id) !== String(levelId));
        Object.keys(state.manualLevels).forEach(key => {
            if (String(state.manualLevels[key]) === String(levelId)) {
                delete state.manualLevels[key];
            }
        });
        markPromptGenerated();
    };

    const togglePromptLock = () => {
        state.promptLocked = !state.promptLocked;
        if (state.promptLocked) {
            state.promptDirty = false;
            state.editablePrompt = generateFullPrompt();
        } else {
            state.editablePrompt = promptValue();
        }
    };

    const setManualType = type => {
        state.manualEvalType = type;
        state.manualLevels = {};
        state.manualFeedback = {};
        state.manualObservations = '';
        if (type === 'random') {
            state.manualEvalStudent = randomStudentId();
            state.manualSubmission = defaultSubmissionForStudent(state.manualEvalStudent);
        } else {
            state.manualEvalStudent = '';
            state.manualSubmission = '';
        }
    };

    const setTestSource = type => {
        state.testCodeSource = type;
        state.latestAiResult = null;
        if (type === 'random') {
            state.randomStudentId = randomStudentId();
            state.selectedTestStudent = '';
            state.testSubmission = defaultSubmissionForStudent(state.randomStudentId);
        } else {
            state.selectedTestStudent = '';
            state.randomStudentId = '';
            state.testSubmission = '';
        }
    };

    const saveConfiguration = async() => {
        if (!state.selectedVPL) {
            throw new Error('Selecciona una actividad VPL.');
        }

        state.savingConfig = true;
        renderMainAction();
        try {
            const oldCriteria = clone(state.rubricCriteria);
            const data = await request('save_config', {
                vplid: Number(state.selectedVPL),
                prompt: promptValue(),
                criteria: serialiseCriteria()
            });

            state.config = data.config;
            state.rubricCriteria = normaliseCriteria(data.criteria || []);
            state.manuals = data.manuals || state.manuals;
            state.aiTests = data.aiTests || state.aiTests;
            remapManualSelections(oldCriteria, state.rubricCriteria);
            state.savingConfig = false;
            showToast(data.message || 'Configuración guardada.');
            renderAll();
            return data;
        } catch (error) {
            state.savingConfig = false;
            renderAll();
            showToast(error.message || 'No se pudo guardar la configuración.');
            throw error;
        }
    };

    const openResultsView = async() => {
        try {
            await saveConfiguration();
            await loadResultsState(state.config.id);
            state.view = 'results';
            renderAll();
            scrollTo(rootSelector);
        } catch (error) {
            showToast(error.message || 'No se pudo abrir la gestión de resultados.');
        }
    };

    const loadResultsState = async configId => {
        const data = await request('get_results_state', {configid: Number(configId)});
        state.resultsState = data;
        state.results = data.results || [];
        state.resultSummary = data.summary || state.resultSummary;
        state.selectedResults = [];
        state.resultSearchTerm = '';
        state.resultStatusFilter = 'all';
        state.publicationFilter = 'all';
        return data;
    };

    const runOneResult = async resultId => {
        if (!resultId || state.bulkRunning) {
            return;
        }
        state.bulkRunning = true;
        markResultStatus(resultId, 'processing');
        renderResultsView();
        try {
            const data = await request('run_result_ai', {resultid: Number(resultId)});
            applyResultsResponse(data);
            showToast(data.message || 'Resultado IA guardado.');
        } catch (error) {
            showToast(error.message || 'No se pudo evaluar la entrega.');
        }
        state.bulkRunning = false;
        renderResultsView();
    };

    const runSelectedResults = async() => {
        const ids = selectedRunnableResults().map(result => String(result.id));
        if (!ids.length) {
            return;
        }

        state.bulkRunning = true;
        for (const id of ids) {
            markResultStatus(id, 'processing');
            renderResultsView();
            try {
                const data = await request('run_result_ai', {resultid: Number(id)});
                applyResultsResponse(data);
            } catch (error) {
                showToast(error.message || 'No se pudo evaluar una entrega.');
            }
        }
        state.bulkRunning = false;
        state.selectedResults = [];
        renderResultsView();
        showToast('Evaluación masiva finalizada.');
    };

    const openResultDrawer = async(resultId, mode) => {
        const result = findResult(resultId);
        if (!result) {
            return;
        }
        state.activeResult = result;
        state.resultDrawerMode = mode;
        state.resultDraft = {
            finaltotalgrade: result.finaltotalgrade === null
                ? (result.aitotalgrade === null ? '' : String(result.aitotalgrade))
                : String(result.finaltotalgrade),
            finalfeedback: result.finalfeedback || resultAiFeedback(result),
            studentfeedback: result.studentfeedback || '',
        };
        renderResultDrawer();
        await loadResultSubmission(result);
        renderResultDrawer();
    };

    const saveResultReview = async() => {
        if (!state.activeResult) {
            return;
        }
        state.savingResult = true;
        renderResultDrawer();
        try {
            const data = await request('save_result_review', {
                resultid: Number(state.activeResult.id),
                finaltotalgrade: Number(state.resultDraft.finaltotalgrade || 0),
                finalfeedback: state.resultDraft.finalfeedback,
                studentfeedback: state.resultDraft.studentfeedback,
            });
            applyResultsResponse(data);
            state.activeResult = data.result;
            state.resultDrawerMode = 'view';
            showToast(data.message || 'Resultado guardado.');
        } catch (error) {
            showToast(error.message || 'No se pudo guardar la revisión.');
        }
        state.savingResult = false;
        renderResultsView();
    };

    const publishResult = async resultId => {
        const id = resultId || (state.activeResult && state.activeResult.id);
        if (!id) {
            return;
        }
        try {
            const data = await request('publish_result', {resultid: Number(id)});
            applyResultsResponse(data);
            if (state.activeResult && String(state.activeResult.id) === String(id)) {
                state.activeResult = data.result;
            }
            showToast(data.message || 'Resultado publicado.');
        } catch (error) {
            showToast(error.message || 'No se pudo publicar el resultado.');
        }
        renderResultsView();
    };

    const publishSelectedResults = async() => {
        const ids = selectedPublishableResults().map(result => String(result.id));
        for (const id of ids) {
            try {
                const data = await request('publish_result', {resultid: Number(id)});
                applyResultsResponse(data);
            } catch (error) {
                showToast(error.message || 'No se pudo publicar un resultado.');
            }
        }
        state.selectedResults = [];
        renderResultsView();
    };

    const applyResultsResponse = data => {
        state.results = data.results || state.results;
        state.resultSummary = data.summary || state.resultSummary;
        if (data.result && state.activeResult && String(data.result.id) === String(state.activeResult.id)) {
            state.activeResult = data.result;
        }
    };

    const loadResultSubmission = async result => {
        if (!result || state.submissions[result.submissionid] || state.loadingSubmissions[result.submissionid]) {
            return;
        }
        state.loadingSubmissions[result.submissionid] = true;
        try {
            const data = await request('get_submission', {
                vplid: Number(state.resultsState.activity.id),
                studentid: Number(result.studentid),
                submissionid: Number(result.submissionid)
            });
            state.submissions[result.submissionid] = data;
        } catch (error) {
            showToast(error.message || 'No se pudo cargar la entrega.');
        }
        delete state.loadingSubmissions[result.submissionid];
    };

    const saveManualEvaluation = async() => {
        if (!isManualComplete()) {
            return;
        }

        state.savingManual = true;
        renderManualCard();
        try {
            await saveConfiguration();
            const data = await request('save_manual', {
                configid: Number(state.config.id),
                manualid: Number(state.manualEditingId || 0),
                studentid: Number(state.manualEvalStudent),
                submissionid: Number(state.manualSubmission),
                selectiontype: state.manualEvalType || 'specific',
                generalobservations: state.manualObservations,
                criteria: state.rubricCriteria.map(criterion => ({
                    criterionid: Number(criterion.id),
                    levelid: Number(state.manualLevels[criterion.id]),
                    observation: state.manualFeedback[criterion.id] || ''
                }))
            });

            state.manuals = data.manuals || [];
            resetManualDraft();
            state.savingManual = false;
            renderManualCard();
            showToast(data.message || 'Referencia manual guardada.');
        } catch (error) {
            state.savingManual = false;
            renderManualCard();
            showToast(error.message || 'No se pudo guardar la referencia manual.');
        }
    };

    const deleteManual = async manualId => {
        try {
            const data = await request('delete_manual', {manualid: Number(manualId)});
            state.manuals = data.manuals || [];
            if (String(state.manualEditingId) === String(manualId)) {
                resetManualDraft();
            }
            renderManualCard();
            showToast(data.message || 'Calificación manual eliminada.');
        } catch (error) {
            showToast(error.message || 'No se pudo eliminar la calificación manual.');
        }
    };

    const editManual = async manualId => {
        const manual = state.manuals.find(item => String(item.id) === String(manualId));
        if (!manual) {
            return;
        }

        state.manualEditingId = String(manual.id);
        state.manualEvalType = manual.selectiontype || 'specific';
        state.manualEvalStudent = String(manual.studentid);
        state.manualSubmission = String(manual.submissionid);
        state.manualObservations = manual.generalobservations || '';
        state.manualLevels = {};
        state.manualFeedback = {};
        (manual.details || []).forEach(detail => {
            state.manualLevels[String(detail.criterionid)] = String(detail.levelid);
            state.manualFeedback[String(detail.criterionid)] = detail.observation || '';
        });
        renderManualCard();
        await loadSelectedSubmission('manual');
    };

    const resetManualDraft = () => {
        state.manualEditingId = '';
        state.manualEvalType = null;
        state.manualEvalStudent = '';
        state.manualSubmission = '';
        state.manualLevels = {};
        state.manualFeedback = {};
        state.manualObservations = '';
    };

    const generatePreview = async() => {
        if (!canGeneratePreview()) {
            return;
        }

        state.isGenerating = true;
        state.latestAiResult = null;
        renderAiTestCard();

        try {
            await saveConfiguration();
            const data = await request('run_ai_test', {
                configid: Number(state.config.id),
                studentid: Number(selectedTestStudentId()),
                submissionid: Number(state.testSubmission)
            });

            state.latestAiResult = data.test;
            state.aiTests = data.aiTests || [];
            state.isGenerating = false;
            renderAiTestCard();
            showToast(data.message || 'Prueba IA guardada.');
        } catch (error) {
            state.isGenerating = false;
            renderAiTestCard();
            showToast(error.message || 'No se pudo ejecutar la prueba IA.');
        }
    };

    const deleteAiTest = async testId => {
        try {
            const data = await request('delete_ai_test', {testid: Number(testId)});
            state.aiTests = data.aiTests || [];
            if (state.latestAiResult && String(state.latestAiResult.id) === String(testId)) {
                state.latestAiResult = null;
            }
            renderAiTestCard();
            showToast(data.message || 'Prueba IA eliminada.');
        } catch (error) {
            showToast(error.message || 'No se pudo eliminar la prueba IA.');
        }
    };

    const loadSelectedSubmission = async type => {
        const submissionId = type === 'manual' ? state.manualSubmission : state.testSubmission;
        const studentId = type === 'manual' ? state.manualEvalStudent : selectedTestStudentId();
        if (!submissionId || !studentId || state.submissions[submissionId] || state.loadingSubmissions[submissionId]) {
            return;
        }

        state.loadingSubmissions[submissionId] = true;
        if (type === 'manual') {
            renderManualCard();
        } else {
            renderAiTestCard();
        }

        try {
            const data = await request('get_submission', {
                vplid: Number(state.selectedVPL),
                studentid: Number(studentId),
                submissionid: Number(submissionId)
            });
            state.submissions[submissionId] = data;
        } catch (error) {
            showToast(error.message || 'No se pudo cargar la entrega.');
        }

        delete state.loadingSubmissions[submissionId];
        if (type === 'manual') {
            renderManualCard();
        } else {
            renderAiTestCard();
        }
    };

    const request = async(action, payload) => {
        const response = await Ajax.call([{
            methodname: 'local_ai_grading_request',
            args: {
                courseid: state.courseid,
                action,
                payload: JSON.stringify(payload || {})
            }
        }])[0];

        if (!response.success) {
            throw new Error(response.message || 'No se pudo completar la operación.');
        }

        try {
            return JSON.parse(response.data || '{}');
        } catch (error) {
            throw new Error('La respuesta del backend no es JSON válido.');
        }
    };

    const generateFullPrompt = () => {
        let prompt = '';

        if (state.selectedActivity) {
            prompt += `DESCRIPCION DEL PROBLEMA\n\n${state.selectedActivity.description || state.selectedActivity.name}\n\n`;
        }

        if (state.rubricCriteria.length > 0) {
            prompt += 'CRITERIOS DE EVALUACION\n\n';
            prompt += state.rubricCriteria.map(criterion => {
                const levelsText = criterion.levels
                    .map(level => `  - ${level.name} (${formatNumber(level.percentage)}%): ${level.description}`)
                    .join('\n');
                return `- ${criterion.name} (${formatNumber(criterion.weight)}% del total): ${criterion.description}\n${levelsText}`;
            }).join('\n\n');
        }

        return prompt;
    };

    const syncPromptIfNeeded = () => {
        if (state.promptLocked || !state.promptDirty) {
            state.editablePrompt = generateFullPrompt();
        }
    };

    const markPromptGenerated = () => {
        if (state.promptLocked || !state.promptDirty) {
            state.editablePrompt = generateFullPrompt();
        }
    };

    const promptValue = () => state.promptLocked ? generateFullPrompt() : (state.editablePrompt || generateFullPrompt());

    const serialiseCriteria = () => state.rubricCriteria.map((criterion, index) => ({
        id: numericIdOrTemp(criterion.id),
        name: criterion.name,
        description: criterion.description,
        weight: Number(criterion.weight || 0),
        sortorder: index + 1,
        levels: criterion.levels.map((level, levelIndex) => ({
            id: numericIdOrTemp(level.id),
            name: level.name,
            percentage: Number(level.percentage || 0),
            description: level.description,
            sortorder: levelIndex + 1
        }))
    }));

    const normaliseCriteria = criteria => criteria.map(criterion => ({
        id: String(criterion.id),
        name: criterion.name || '',
        description: criterion.description || '',
        weight: Number(criterion.weight || 0),
        sortorder: Number(criterion.sortorder || 0),
        levels: (criterion.levels || []).map(level => ({
            id: String(level.id),
            criterionid: String(level.criterionid || criterion.id),
            name: level.name || '',
            percentage: Number(level.percentage || 0),
            description: level.description || '',
            sortorder: Number(level.sortorder || 0)
        }))
    }));

    const remapManualSelections = (oldCriteria, newCriteria) => {
        const levels = {};
        const feedback = {};
        oldCriteria.forEach((oldCriterion, index) => {
            const newCriterion = newCriteria[index];
            if (!newCriterion) {
                return;
            }
            if (Object.prototype.hasOwnProperty.call(state.manualFeedback, oldCriterion.id)) {
                feedback[newCriterion.id] = state.manualFeedback[oldCriterion.id];
            }
            const selectedLevel = state.manualLevels[oldCriterion.id];
            if (!selectedLevel) {
                return;
            }
            const oldLevelIndex = oldCriterion.levels.findIndex(level => String(level.id) === String(selectedLevel));
            if (oldLevelIndex >= 0 && newCriterion.levels[oldLevelIndex]) {
                levels[newCriterion.id] = newCriterion.levels[oldLevelIndex].id;
            }
        });
        state.manualLevels = levels;
        state.manualFeedback = feedback;
    };

    const canGeneratePreview = () => {
        return !state.isGenerating
            && Boolean(state.selectedVPL)
            && Boolean(selectedTestStudentId())
            && Boolean(state.testSubmission)
            && state.rubricCriteria.every(criterion => criterion.levels.length > 0);
    };

    const selectedTestStudentId = () => state.testCodeSource === 'student' ? state.selectedTestStudent : state.randomStudentId;

    const isManualComplete = () => Boolean(state.selectedVPL && state.manualEvalStudent && state.manualSubmission)
        && state.rubricCriteria.every(criterion => Boolean(state.manualLevels[criterion.id]));

    const manualTotal = () => {
        return state.rubricCriteria.reduce((sum, criterion) => {
            const level = criterion.levels.find(item => String(item.id) === String(state.manualLevels[criterion.id]));
            return sum + (level ? (Number(criterion.weight || 0) * Number(level.percentage || 0)) / 100 : 0);
        }, 0);
    };

    const aiResultTotal = result => {
        const details = result.details || [];
        if (details.length) {
            return Math.round(details.reduce((sum, item) => sum + Number(item.score || 0), 0) * 100) / 100;
        }
        return Number(result.totalgrade || 0);
    };

    const gradePercent = (score, max) => {
        const maximum = Number(max || 0);
        if (maximum <= 0) {
            return 0;
        }
        return numberInRange((Number(score || 0) / maximum) * 100, 0, 100);
    };

    const gradeHue = percent => {
        const value = numberInRange(percent, 0, 100);
        if (value < 60) {
            return 4 + (value / 60) * 28;
        }
        if (value < 80) {
            return 42 + ((value - 60) / 20) * 14;
        }
        return 80 + ((value - 80) / 20) * 58;
    };

    const gradeStyle = (score, max) => {
        const hue = Math.round(gradeHue(gradePercent(score, max)));
        return `--ag-grade-hue: ${hue};`;
    };

    const totalWeight = () => state.rubricCriteria.reduce((sum, criterion) => sum + Number(criterion.weight || 0), 0);

    const updateTotalText = () => {
        const totalNode = root.querySelector('.ag-total');
        if (!totalNode) {
            return;
        }
        const total = totalWeight();
        totalNode.classList.toggle('is-ok', total === 100);
        totalNode.classList.toggle('is-error', total !== 100);
        totalNode.textContent = `Total: ${formatNumber(total)}% ${total !== 100 ? '(debe sumar 100%)' : ''}`;
    };

    const updateManualTotal = () => {
        const node = root.querySelector('.ag-manual-total strong');
        if (node) {
            node.textContent = `${formatNumber(manualTotal())}/100`;
        }
        const button = root.querySelector('[data-action="save-manual"]');
        if (button) {
            button.disabled = !isManualComplete() || state.savingManual;
        }
    };

    const filteredResults = () => {
        const search = state.resultSearchTerm.toLowerCase();
        return state.results.filter(result => {
            const matchesSearch = result.studentName.toLowerCase().includes(search)
                || result.studentUsername.toLowerCase().includes(search);
            const matchesStatus = state.resultStatusFilter === 'all' || result.aistatus === state.resultStatusFilter;
            const matchesPublication = state.publicationFilter === 'all'
                || result.publicationStatus === state.publicationFilter;
            return matchesSearch && matchesStatus && matchesPublication;
        });
    };

    const findResult = resultId => state.results.find(result => String(result.id) === String(resultId));

    const markResultStatus = (resultId, status) => {
        state.results = state.results.map(result => String(result.id) === String(resultId)
            ? Object.assign({}, result, {aistatus: status})
            : result);
    };

    const selectedRunnableResults = () => state.results.filter(result =>
        state.selectedResults.includes(String(result.id))
        && result.publicationStatus !== 'published'
        && !state.bulkRunning
    );

    const selectedPublishableResults = () => state.results.filter(result =>
        state.selectedResults.includes(String(result.id)) && isPublishable(result)
    );

    const isPublishable = result => result
        && result.aistatus === 'evaluated'
        && result.aitotalgrade !== null
        && result.publicationStatus !== 'published';

    const toggleResult = resultId => {
        const id = String(resultId);
        if (state.selectedResults.includes(id)) {
            state.selectedResults = state.selectedResults.filter(item => item !== id);
        } else {
            state.selectedResults.push(id);
        }
    };

    const toggleAllResults = () => {
        const filtered = filteredResults();
        if (state.selectedResults.length === filtered.length) {
            state.selectedResults = [];
        } else {
            state.selectedResults = filtered.map(result => String(result.id));
        }
    };

    const resultMetric = (label, value) => `
        <div>
            <strong>${formatNumber(value)}</strong>
            <span>${escapeHtml(label)}</span>
        </div>
    `;

    const resultStatusBadge = status => {
        const labels = {
            pending: 'Pendiente',
            processing: 'En proceso',
            evaluated: 'Evaluado',
            error: 'Error',
        };
        return `<span class="ag-status-badge ag-status-${escapeAttr(status)}">${escapeHtml(labels[status] || status)}</span>`;
    };

    const publicationBadge = status => status === 'published'
        ? '<span class="ag-status-badge ag-status-published">Publicado</span>'
        : '<span class="ag-status-badge ag-status-outline">No publicado</span>';

    const resultAiFeedback = result => {
        const details = result.details || [];
        if (!details.length) {
            return result.errordetail || '';
        }
        return details.map(item => `${item.criterionName}: ${item.detail || 'Sin detalle.'}`).join('\n\n');
    };

    const resultFeedbackPreview = result => {
        const text = result.finalfeedback || resultAiFeedback(result) || result.errordetail || 'Sin feedback disponible';
        return text.length > 150 ? `${text.slice(0, 150)}...` : text;
    };

    const choiceButton = (id, value, current, title, description, action, shuffle) => `
        <button type="button" id="${id}" class="ag-choice ${current === value ? 'is-selected' : ''}"
            data-action="${action}" data-value="${value}" ${state.selectedVPL && state.students.length ? '' : 'disabled'}>
            <strong>${shuffle ? '<span class="ag-shuffle-icon" aria-hidden="true"></span>' : ''}${escapeHtml(title)}</strong>
            <span>${escapeHtml(description)}</span>
        </button>
    `;

    const studentSelect = (id, value, placeholder, action) => `
        <div class="ag-field ag-student-select">
            <label for="${id}">Selecciona un estudiante</label>
            <select id="${id}" class="ag-select" data-action="${action}">
                <option value="">${escapeHtml(placeholder)}</option>
                ${state.students.map(student => `
                    <option value="${student.id}" ${String(student.id) === String(value) ? 'selected' : ''}>
                        ${escapeHtml(student.name)} (${student.submissions.length})
                    </option>
                `).join('')}
            </select>
        </div>
    `;

    const submissionSelect = (id, studentId, value, action) => {
        const student = findStudent(studentId);
        const submissions = student ? student.submissions : [];
        return `
            <div class="ag-field ag-student-select">
                <label for="${id}">Selecciona una entrega</label>
                <select id="${id}" class="ag-select" data-action="${action}">
                    <option value="">Elige una entrega</option>
                    ${submissions.map(submission => `
                        <option value="${submission.id}" ${String(submission.id) === String(value) ? 'selected' : ''}>
                            Intento ${submission.attemptNo} · ${escapeHtml(submission.dateSubmittedText)}
                        </option>
                    `).join('')}
                </select>
            </div>
        `;
    };

    const selectedStudentMessage = (studentId, color, label) => {
        const student = findStudent(studentId);
        return `
            <div class="ag-info-box ag-info-box--${color}">
                ${escapeHtml(label)}: <strong>${escapeHtml(student ? student.name : '')}</strong>
            </div>
        `;
    };

    const codeBlock = (title, submission) => {
        const files = submission.files || [];
        const output = submission.stdout || submission.execution_output || submission.grade_comments || submission.compilation_output || '';
        return `
            <div class="ag-code-block">
                <label>${escapeHtml(title)} ${submission.attemptNo ? `(intento ${submission.attemptNo})` : ''}</label>
                <pre>${escapeHtml(submission.source_code || 'No se encontraron archivos de código para esta entrega.')}</pre>
                <div class="ag-io-grid">
                    <div><strong>Archivos:</strong> ${files.length ? escapeHtml(files.map(file => file.filename).join(', ')) : 'Sin archivos'}</div>
                    <div><strong>Salida:</strong> ${escapeHtml(output || 'Sin salida disponible')}</div>
                </div>
            </div>
        `;
    };

    const findCriterion = criterionId => state.rubricCriteria.find(item => String(item.id) === String(criterionId));

    const findStudent = studentId => state.students.find(student => String(student.id) === String(studentId));

    const defaultSubmissionForStudent = studentId => {
        const student = findStudent(studentId);
        return student && student.submissions.length ? String(student.submissions[0].id) : '';
    };

    const randomStudentId = () => {
        if (!state.students.length) {
            return '';
        }
        const index = Math.floor(Math.random() * state.students.length);
        return String(state.students[index].id);
    };

    const createId = prefix => {
        idCounter += 1;
        return `tmp-${prefix}-${Date.now()}-${idCounter}`;
    };

    const numericIdOrTemp = value => /^\d+$/.test(String(value)) ? Number(value) : value;

    const numberInRange = (value, min, max) => {
        const number = parseFloat(value);
        if (Number.isNaN(number)) {
            return min;
        }
        return Math.min(max, Math.max(min, number));
    };

    const formatNumber = value => {
        const number = Number(value || 0);
        return Number.isInteger(number) ? String(number) : number.toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
    };

    const scrollTo = id => {
        const node = document.getElementById(id);
        if (node) {
            node.scrollIntoView({behavior: 'smooth', block: 'center'});
        }
    };

    const showToast = message => {
        const toast = region('toast');
        toast.textContent = message;
        toast.hidden = false;
        clearTimeout(showToast.timer);
        showToast.timer = setTimeout(() => {
            toast.hidden = true;
        }, 4200);
    };

    const region = name => root.querySelector(`[data-region="${name}"]`);

    const clone = value => JSON.parse(JSON.stringify(value));

    const escapeHtml = value => String(value === null || value === undefined ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const escapeAttr = escapeHtml;

    return {
        init
    };
});
