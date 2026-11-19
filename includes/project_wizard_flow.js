            var postProjectCreateFlow = {
                active: false,
                projectId: 0,
                projectName: '',
                dataMode: 'qbo_after',
                previousActiveProjectId: 0,
                step: null,
                importCompleted: false,
                purposeCheckInFlight: false,
                postCreateCsvImportInFlight: false,
                copyPurposes: null,
                copyChats: null,
                copyCategories: false,
                overwriteBlankPurposes: false,
                hasPriorProjects: false,
                vendorCount: 0,
                orgMemberCount: typeof TEAM_MEMBERS_COUNT === 'number' ? TEAM_MEMBERS_COUNT : 1,
                visibleSteps: [],
                tourIndex: 0
            };
            var QBO_ORG_CONNECTED = <?php echo !empty($qbo_status['connected']) ? 'true' : 'false'; ?>;
            var POST_CREATE_FLOW_STORAGE_KEY = 'savvy_post_create_qbo_flow';
            var WIZARD_TOUR_STORAGE_PREFIX = 'savvy_wizard_tour_done_';

            var WIZARD_STEP_DEFS = [
                { id: 'name', label: 'Name' },
                { id: 'qbo_connect', label: 'QuickBooks' },
                { id: 'qbo_range', label: 'Retrieve' },
                { id: 'qbo_import', label: 'Import' },
                { id: 'purposes', label: 'Purposes' },
                { id: 'chats', label: 'Chats' },
                { id: 'review', label: 'Review' },
                { id: 'tour', label: 'Tour' },
                { id: 'invite', label: 'Invite' },
                { id: 'done', label: 'Done' }
            ];

            var WIZARD_COACH_STEPS = [
                {
                    selector: 'th.visibility-col',
                    text: 'All vendors are imported as confidential. Any invited user will not be able to see confidential data.'
                },
                {
                    selector: 'th.frequency',
                    text: 'Saver has determined the frequency of vendor payments for you, however we urge you to review to ensure they are correct.'
                },
                {
                    selector: 'th.cost-per-period',
                    text: 'Please review costs that have been updated by Savvy Saver.'
                },
                {
                    selector: '#costCalculatorGrid tbody tr:not([hidden]) .vendor-raw-btn',
                    text: 'Where the costs are $0, review the transactions for the vendor by clicking here and enter the cost to be used for the vendor.'
                }
            ];

            function isPostCreateUploadWaiting() {
                return postProjectCreateFlow.active && (
                    postProjectCreateFlow.step === 'upload_waiting_import'
                    || postProjectCreateFlow.step === 'qbo_waiting_sync'
                    || postProjectCreateFlow.step === 'qbo_range'
                    || postProjectCreateFlow.step === 'qbo_import'
                );
            }

            function isPostCreateQboWaiting() {
                return postProjectCreateFlow.active && (
                    postProjectCreateFlow.step === 'qbo_waiting_sync'
                    || postProjectCreateFlow.step === 'qbo_range'
                    || postProjectCreateFlow.step === 'qbo_import'
                );
            }

            function isPostCreateCsvModalId(modalId) {
                return modalId === 'appModalCsvAccounts' || modalId === 'appModalCsvMapping' || modalId === 'appModalQboDateRange';
            }

            function savePostCreateFlowToSessionStorage() {
                try {
                    sessionStorage.setItem(POST_CREATE_FLOW_STORAGE_KEY, JSON.stringify({
                        active: true,
                        projectId: postProjectCreateFlow.projectId,
                        projectName: postProjectCreateFlow.projectName,
                        previousActiveProjectId: postProjectCreateFlow.previousActiveProjectId,
                        step: 'qbo_range',
                        dataMode: postProjectCreateFlow.dataMode || 'qbo_after'
                    }));
                } catch (e) {}
            }

            function loadPostCreateFlowFromSessionStorage() {
                try {
                    var raw = sessionStorage.getItem(POST_CREATE_FLOW_STORAGE_KEY);
                    if (!raw) return null;
                    var data = JSON.parse(raw);
                    return data && typeof data === 'object' ? data : null;
                } catch (e) {
                    return null;
                }
            }

            function clearPostCreateFlowSessionStorage() {
                try { sessionStorage.removeItem(POST_CREATE_FLOW_STORAGE_KEY); } catch (e) {}
            }

            function wizardTourStorageKey() {
                var org = typeof window.CURRENT_ORG_ID !== 'undefined' ? window.CURRENT_ORG_ID : '0';
                var uid = typeof window.CURRENT_USER_ID !== 'undefined' ? window.CURRENT_USER_ID : '0';
                return WIZARD_TOUR_STORAGE_PREFIX + org + '_' + uid;
            }

            function showWizardProgressBar() {
                var bar = document.getElementById('wizardProgressBar');
                if (bar) {
                    bar.hidden = false;
                    bar.classList.add('is-visible');
                }
                document.body.classList.add('wizard-active');
            }

            function hideWizardProgressBar() {
                var bar = document.getElementById('wizardProgressBar');
                if (bar) {
                    bar.hidden = true;
                    bar.classList.remove('is-visible');
                }
                document.body.classList.remove('wizard-active');
            }

            function rebuildWizardVisibleSteps(opts) {
                opts = opts || {};
                var steps = ['name'];
                var needsImportPath = postProjectCreateFlow.dataMode === 'qbo_after'
                    || postProjectCreateFlow.dataMode === 'upload_after'
                    || opts.forceImportPath;
                if (needsImportPath && !postProjectCreateFlow.importCompleted) {
                    if (!QBO_ORG_CONNECTED) {
                        steps.push('qbo_connect');
                    }
                    steps.push('qbo_range');
                    steps.push('qbo_import');
                }
                var hasVendors = (postProjectCreateFlow.vendorCount || 0) > 0 || postProjectCreateFlow.importCompleted;
                if (hasVendors || opts.assumeVendors) {
                    steps.push('purposes');
                    if (postProjectCreateFlow.hasPriorProjects) {
                        steps.push('chats');
                    }
                    steps.push('review');
                    steps.push('tour');
                }
                if ((postProjectCreateFlow.orgMemberCount || 1) <= 1) {
                    steps.push('invite');
                }
                steps.push('done');
                // When still on name / early, keep vendor-dependent steps in the plan so the bar foreshadows the path.
                if (!hasVendors && !opts.assumeVendors && (opts.atName || postProjectCreateFlow.step === 'name'
                    || postProjectCreateFlow.step === 'qbo_connect'
                    || postProjectCreateFlow.step === 'qbo_range'
                    || postProjectCreateFlow.step === 'qbo_import'
                    || postProjectCreateFlow.step === 'qbo'
                    || postProjectCreateFlow.step === 'qbo_waiting_sync')) {
                    var foreshadow = ['purposes', 'review', 'tour'];
                    if (postProjectCreateFlow.hasPriorProjects) {
                        foreshadow.splice(1, 0, 'chats');
                    }
                    foreshadow.forEach(function(id) {
                        if (steps.indexOf(id) === -1) {
                            var doneIdx = steps.indexOf('done');
                            var inviteIdx = steps.indexOf('invite');
                            var insertAt = inviteIdx >= 0 ? inviteIdx : (doneIdx >= 0 ? doneIdx : steps.length);
                            steps.splice(insertAt, 0, id);
                        }
                    });
                }
                postProjectCreateFlow.visibleSteps = steps;
                return steps;
            }

            function wizardStepIndex(stepId) {
                var steps = postProjectCreateFlow.visibleSteps || [];
                return steps.indexOf(stepId);
            }

            function updateWizardProgressBar() {
                var bar = document.getElementById('wizardProgressBar');
                if (!bar) return;
                var steps = postProjectCreateFlow.visibleSteps || [];
                if (!steps.length) {
                    rebuildWizardVisibleSteps({ atName: postProjectCreateFlow.step === 'name' });
                    steps = postProjectCreateFlow.visibleSteps || [];
                }
                var stepId = postProjectCreateFlow.step;
                if (stepId === 'qbo' || stepId === 'qbo_waiting_sync') {
                    stepId = QBO_ORG_CONNECTED ? 'qbo_range' : 'qbo_connect';
                }
                if (stepId === 'purpose' || stepId === 'purpose_check' || stepId === 'purpose_done') {
                    stepId = 'purposes';
                }
                if (stepId === 'invite_open') {
                    stepId = 'invite';
                }
                if (stepId === 'upload' || stepId === 'upload_waiting_import') {
                    stepId = 'qbo_import';
                }
                var idx = steps.indexOf(stepId);
                if (idx < 0) {
                    idx = 0;
                    stepId = steps[0] || 'name';
                }
                var total = Math.max(1, steps.length);
                var pct = Math.round(((idx + 1) / total) * 100);
                var fill = document.getElementById('wizardProgressFill');
                if (fill) fill.style.width = pct + '%';
                var meta = document.getElementById('wizardProgressMeta');
                var def = WIZARD_STEP_DEFS.find(function(s) { return s.id === stepId; });
                var label = def ? def.label : stepId;
                var name = postProjectCreateFlow.projectName || 'your project';
                if (meta) {
                    meta.innerHTML = '<span class="wizard-progress-bar__title">Setting up '
                        + escapeHtml(name) + '</span>'
                        + '<span class="wizard-progress-bar__step-label">Step '
                        + (idx + 1) + ' of ' + total + ' · ' + escapeHtml(label) + '</span>';
                }
                bar.setAttribute('aria-valuenow', String(idx + 1));
                bar.setAttribute('aria-valuemax', String(total));
                bar.setAttribute('aria-label', 'Project setup progress: step ' + (idx + 1) + ' of ' + total + ', ' + label);
                var ticks = document.getElementById('wizardProgressSteps');
                if (ticks) {
                    ticks.innerHTML = steps.map(function(id, i) {
                        var d = WIZARD_STEP_DEFS.find(function(s) { return s.id === id; });
                        var cls = 'wizard-progress-bar__tick';
                        if (i < idx) cls += ' is-done';
                        if (i === idx) cls += ' is-current';
                        return '<div class="' + cls + '">' + escapeHtml(d ? d.label : id) + '</div>';
                    }).join('');
                }
            }

            function setWizardStep(stepId) {
                postProjectCreateFlow.step = stepId;
                rebuildWizardVisibleSteps({
                    atName: stepId === 'name',
                    assumeVendors: ['purposes', 'chats', 'review', 'tour', 'invite', 'done'].indexOf(stepId) !== -1
                        || postProjectCreateFlow.importCompleted
                });
                updateWizardProgressBar();
                showWizardProgressBar();
            }

            function openPostCreateQboSyncModal() {
                setWizardStep('qbo_range');
                closeAppModal('appModalPostCreateUpload');
                var range = defaultQboDateRange();
                var startEl = document.getElementById('qboSyncStartDate');
                var endEl = document.getElementById('qboSyncEndDate');
                if (startEl) startEl.value = range.start;
                if (endEl) endEl.value = range.end;
                var titleEl = document.getElementById('appModalQboDateRangeTitle');
                if (titleEl) titleEl.textContent = 'Retrieve QuickBooks data';
                prepareQboWeeklyImportConsent().finally(function() {
                    openAppModal('appModalQboDateRange');
                });
            }

            function reopenPostCreateUploadModal() {
                postProjectCreateFlow.importCompleted = false;
                if (QBO_ORG_CONNECTED) {
                    openPostCreateQboSyncModal();
                    return;
                }
                setWizardStep('qbo_connect');
                refreshPostCreateQboButtons();
                openAppModal('appModalPostCreateUpload');
            }

            function refreshPostCreateQboButtons() {
                var connectBtn = document.getElementById('postCreateQboConnectBtn');
                var syncBtn = document.getElementById('postCreateQboSyncBtn');
                if (connectBtn) connectBtn.style.display = QBO_ORG_CONNECTED ? 'none' : '';
                if (syncBtn) syncBtn.style.display = QBO_ORG_CONNECTED ? '' : 'none';
            }

            function handlePostCreateImportFailure() {
                if (!isPostCreateUploadWaiting()) return;
                postProjectCreateFlow.postCreateCsvImportInFlight = false;
                closeAppModal('appModalCsvAccounts');
                closeAppModal('appModalCsvMapping');
                closeAppModal('appModalQboDateRange');
                reopenPostCreateUploadModal();
            }

            function maybeReturnToPostCreateUploadAfterCsvClose(modalId) {
                if (!isPostCreateUploadWaiting()) return;
                if (postProjectCreateFlow.postCreateCsvImportInFlight) return;
                if (!isPostCreateCsvModalId(modalId)) return;
                if (modalId === 'appModalCsvAccounts' && postProjectCreateFlow.step === 'qbo_import') {
                    setWizardStep('qbo_range');
                    openAppModal('appModalQboDateRange');
                    return;
                }
                reopenPostCreateUploadModal();
            }

            function markWizardImportAccountsOpen() {
                if (postProjectCreateFlow.active) {
                    setWizardStep('qbo_import');
                }
            }

            function setPostCreateSubtitle(elId) {
                var el = document.getElementById(elId);
                if (!el) return;
                var name = postProjectCreateFlow.projectName || 'your project';
                el.textContent = 'Setting up ' + name;
            }

            function fetchActiveProjectVendorCount() {
                var fd = new FormData();
                fd.append('action', 'load_cost_calculator');
                return fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (!data || !data.success || !Array.isArray(data.items)) return 0;
                        return data.items.filter(function(item) {
                            return String(item.vendor_name || '').trim() !== '';
                        }).length;
                    })
                    .catch(function() { return 0; });
            }

            function fetchHasPriorProjects() {
                return postJson({ action: 'project_list' }).then(function(d) {
                    var count = 0;
                    (d.projects || []).forEach(function(p) {
                        var pid = parseInt(p.id, 10) || 0;
                        if (pid > 0 && pid !== postProjectCreateFlow.projectId) count += 1;
                    });
                    postProjectCreateFlow.hasPriorProjects = count > 0;
                    return postProjectCreateFlow.hasPriorProjects;
                }).catch(function() {
                    postProjectCreateFlow.hasPriorProjects = false;
                    return false;
                });
            }

            function populateWizardSourceSelect(selectId) {
                var select = document.getElementById(selectId);
                if (!select) return Promise.resolve(false);
                return postJson({ action: 'project_list' }).then(function(d) {
                    select.innerHTML = '';
                    var firstOpt = null;
                    (d.projects || []).forEach(function(p) {
                        var pid = parseInt(p.id, 10) || 0;
                        if (pid <= 0 || pid === postProjectCreateFlow.projectId) return;
                        var opt = document.createElement('option');
                        opt.value = String(pid);
                        opt.textContent = p.name || ('Project ' + pid);
                        select.appendChild(opt);
                        if (!firstOpt) firstOpt = opt;
                    });
                    var prev = postProjectCreateFlow.previousActiveProjectId;
                    if (prev > 0 && prev !== postProjectCreateFlow.projectId) {
                        select.value = String(prev);
                    } else if (firstOpt) {
                        select.value = firstOpt.value;
                    }
                    postProjectCreateFlow.hasPriorProjects = select.options.length > 0;
                    return select.options.length > 0;
                });
            }

            function resetPostCreatePurposeModal() {
                postProjectCreateFlow.copyPurposes = null;
                postProjectCreateFlow.overwriteBlankPurposes = false;
                var questionsBlock = document.getElementById('postCreatePurposeQuestionsBlock');
                var sourceBlock = document.getElementById('postCreatePurposeSelectBlock');
                var continueBtn = document.getElementById('postCreatePurposeContinueBtn');
                var proceedBtn = document.getElementById('postCreatePurposeProceedBtn');
                var select = document.getElementById('postCreatePurposeSource');
                if (questionsBlock) questionsBlock.style.display = '';
                if (sourceBlock) sourceBlock.style.display = 'none';
                if (continueBtn) continueBtn.style.display = '';
                if (proceedBtn) proceedBtn.style.display = 'none';
                if (select) select.innerHTML = '';
                document.querySelectorAll('input[name="postCreateCopyPurposes"]').forEach(function(r) { r.checked = false; });
            }

            function resetPostCreateChatsModal() {
                postProjectCreateFlow.copyChats = null;
                var questionsBlock = document.getElementById('postCreateChatsQuestionsBlock');
                var sourceBlock = document.getElementById('postCreateChatsSelectBlock');
                var continueBtn = document.getElementById('postCreateChatsContinueBtn');
                var proceedBtn = document.getElementById('postCreateChatsProceedBtn');
                var select = document.getElementById('postCreateChatsSource');
                if (questionsBlock) questionsBlock.style.display = '';
                if (sourceBlock) sourceBlock.style.display = 'none';
                if (continueBtn) continueBtn.style.display = '';
                if (proceedBtn) proceedBtn.style.display = 'none';
                if (select) select.innerHTML = '';
                document.querySelectorAll('input[name="postCreateCopyChats"]').forEach(function(r) { r.checked = false; });
            }

            function openPostCreatePurposeModal() {
                resetPostCreatePurposeModal();
                setPostCreateSubtitle('postCreatePurposeSubtitle');
                setWizardStep('purposes');
                openAppModal('appModalPostCreatePurpose');
            }

            function openPostCreateChatsModal() {
                resetPostCreateChatsModal();
                setPostCreateSubtitle('postCreateChatsSubtitle');
                setWizardStep('chats');
                openAppModal('appModalPostCreateChats');
            }

            function openPostCreateInviteModal() {
                setPostCreateSubtitle('postCreateInviteSubtitle');
                setWizardStep('invite');
                openAppModal('appModalPostCreateInvite');
            }

            function openSetupCompleteModal() {
                setWizardStep('done');
                openAppModal('appModalSetupComplete');
            }

            function formatMoney(n) {
                var v = Number(n) || 0;
                return '$' + v.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 });
            }

            function openVendorRedundancyModal(data) {
                setWizardStep('review');
                var summary = document.getElementById('wizardReviewSummary');
                var list = document.getElementById('wizardReviewList');
                var msg = document.getElementById('wizardReviewMessage');
                var dupSave = Number(data && data.duplicate_savings) || 0;
                var ovSave = Number(data && data.overlap_savings) || 0;
                if (msg) {
                    msg.textContent = 'Identify vendors you will not use anymore. Based on duplicates you could save '
                        + formatMoney(dupSave) + ', and based on potential overlaps you could save '
                        + formatMoney(ovSave) + '.';
                }
                if (summary) {
                    summary.innerHTML =
                        '<span class="wizard-review-pill wizard-review-pill--dup">Duplicates · save '
                        + formatMoney(dupSave) + '</span>'
                        + '<span class="wizard-review-pill wizard-review-pill--overlap">Overlaps · save '
                        + formatMoney(ovSave) + '</span>';
                }
                var html = '';
                (data.duplicates || []).forEach(function(d) {
                    html += '<div class="wizard-review-row wizard-review-row--dup">'
                        + '<div class="wizard-review-row__title">Duplicate: ' + escapeHtml(d.vendor_name || '') + '</div>'
                        + '<div class="wizard-review-row__meta">' + (d.row_count || 0)
                        + ' rows · extra annual ' + formatMoney(d.extra_annual_cost || 0) + '</div>'
                        + '</div>';
                });
                (data.overlaps || []).forEach(function(o) {
                    html += '<div class="wizard-review-row wizard-review-row--overlap">'
                        + '<div class="wizard-review-row__title">' + escapeHtml(o.vendor_a || '')
                        + ' ↔ ' + escapeHtml(o.vendor_b || '') + '</div>'
                        + '<div class="wizard-review-row__meta">' + escapeHtml(o.reason || 'Potential overlap')
                        + ' · potential save ' + formatMoney(o.suggested_annual_savings || 0) + '</div>'
                        + '</div>';
                });
                if (!html) {
                    html = '<p class="wizard-review-empty">No duplicates or overlaps found.</p>';
                }
                if (list) list.innerHTML = html;
                if (data.overlap_warning) {
                    showSnackbar(data.overlap_warning, 'info');
                }
                openAppModal('appModalVendorRedundancy');
            }

            function runVendorRedundancyStep() {
                setWizardStep('review');
                showSnackbar('Checking for duplicate and overlapping vendors…', 'info');
                return postJson({ action: 'analyze_vendor_redundancy' }).then(function(d) {
                    if (!d || !d.success) {
                        showSnackbar((d && d.error) || 'Could not analyze vendors.', 'error');
                        openVendorRedundancyModal({
                            duplicates: [],
                            overlaps: [],
                            duplicate_savings: 0,
                            overlap_savings: 0
                        });
                        return;
                    }
                    openVendorRedundancyModal(d);
                }).catch(function() {
                    showSnackbar('Could not analyze vendors.', 'error');
                    openVendorRedundancyModal({
                        duplicates: [],
                        overlaps: [],
                        duplicate_savings: 0,
                        overlap_savings: 0
                    });
                });
            }

            function closeWizardCoach() {
                var overlay = document.getElementById('wizardCoachOverlay');
                if (overlay) {
                    overlay.classList.remove('is-open');
                    overlay.setAttribute('aria-hidden', 'true');
                }
            }

            function positionWizardCoach(step) {
                var target = document.querySelector(step.selector);
                var spot = document.getElementById('wizardCoachSpot');
                var bubble = document.getElementById('wizardCoachBubble');
                var textEl = document.getElementById('wizardCoachText');
                var dim = document.getElementById('wizardCoachDim');
                if (!spot || !bubble || !textEl) return false;
                if (!target) {
                    textEl.textContent = step.text;
                    bubble.style.left = '16px';
                    bubble.style.right = '16px';
                    bubble.style.top = 'auto';
                    bubble.style.bottom = '24px';
                    spot.style.display = 'none';
                    if (dim) dim.classList.add('is-on');
                    return true;
                }
                if (dim) dim.classList.remove('is-on');
                target.scrollIntoView({ block: 'center', inline: 'nearest' });
                var rect = target.getBoundingClientRect();
                var pad = 6;
                spot.style.display = 'block';
                spot.style.top = Math.max(0, rect.top - pad) + 'px';
                spot.style.left = Math.max(0, rect.left - pad) + 'px';
                spot.style.width = Math.max(24, rect.width + pad * 2) + 'px';
                spot.style.height = Math.max(24, rect.height + pad * 2) + 'px';
                textEl.textContent = step.text;
                var bubbleWidth = Math.min(360, window.innerWidth - 24);
                var left = Math.min(Math.max(12, rect.left), window.innerWidth - bubbleWidth - 12);
                var top = rect.bottom + 14;
                if (top + 160 > window.innerHeight) {
                    top = Math.max(12, rect.top - 160);
                }
                bubble.style.width = bubbleWidth + 'px';
                bubble.style.left = left + 'px';
                bubble.style.top = top + 'px';
                bubble.style.bottom = 'auto';
                bubble.style.right = 'auto';
                return true;
            }

            function renderWizardCoachStep() {
                var steps = WIZARD_COACH_STEPS;
                var idx = postProjectCreateFlow.tourIndex || 0;
                if (idx >= steps.length) {
                    finishWizardTour();
                    return;
                }
                var overlay = document.getElementById('wizardCoachOverlay');
                if (overlay) {
                    overlay.classList.add('is-open');
                    overlay.setAttribute('aria-hidden', 'false');
                }
                positionWizardCoach(steps[idx]);
                var nextBtn = document.getElementById('wizardCoachNextBtn');
                if (nextBtn) {
                    nextBtn.textContent = (idx >= steps.length - 1) ? 'Got it' : 'Next';
                }
            }

            function startWizardCoachTour() {
                setWizardStep('tour');
                closeAppModal('appModalVendorRedundancy');
                closeAppModal('appModalPostCreatePurpose');
                closeAppModal('appModalPostCreateChats');
                closeAppModal('appModalPostCreateInvite');
                closeAppModal('appModalPostCreateUpload');
                postProjectCreateFlow.tourIndex = 0;
                // Allow grid to paint without modal overlay.
                setTimeout(function() {
                    renderWizardCoachStep();
                }, 80);
            }

            function finishWizardTour() {
                closeWizardCoach();
                try {
                    localStorage.setItem(wizardTourStorageKey(), '1');
                } catch (e) {}
                continueAfterTour();
            }

            function continueAfterTour() {
                if ((postProjectCreateFlow.orgMemberCount || 1) <= 1) {
                    openPostCreateInviteModal();
                } else {
                    openSetupCompleteModal();
                }
            }

            function proceedToPurposeOrInvite() {
                if (postProjectCreateFlow.purposeCheckInFlight) return;
                var projectId = parseInt(postProjectCreateFlow.projectId, 10) || parseInt(currentActiveProjectId, 10) || 0;
                if (!projectId) {
                    continueAfterTour();
                    return;
                }
                postProjectCreateFlow.purposeCheckInFlight = true;
                setWizardStep('purposes');
                var syncActive = postJson({ action: 'project_set_active', project_id: projectId }).then(function(d) {
                    if (d && d.success) {
                        currentActiveProjectId = projectId;
                    }
                }).catch(function() {});
                syncActive.then(function() {
                    return Promise.all([fetchActiveProjectVendorCount(), fetchHasPriorProjects()]);
                }).then(function(results) {
                    var count = results[0] || 0;
                    postProjectCreateFlow.vendorCount = count;
                    if (count === 0) {
                        showSnackbar('No vendors to set up yet. You can import QuickBooks data anytime.', 'info');
                        continueAfterTour();
                        return;
                    }
                    showSnackbar('Populating purposes with AI… For many vendors, this may take up to 3 minutes…', 'info');
                    showAiPopulateLoader('Populating purposes with AI… For many vendors, this may take up to 3 minutes…');
                    clearTimeout(saveTimeout);
                    var populateFn = typeof window.runProjectAutoPopulatePurpose === 'function'
                        ? window.runProjectAutoPopulatePurpose
                        : null;
                    var waitForSaves = typeof window.waitForCalculatorSaveIdle === 'function'
                        ? window.waitForCalculatorSaveIdle()
                        : Promise.resolve();
                    var populatePromise = waitForSaves.then(function() {
                        if (!populateFn) {
                            return Promise.resolve({ success: false, error: 'Auto populate is not ready yet.' });
                        }
                        return populateFn(projectId, { silent: true, skipClientSave: true, hideLoader: true });
                    });
                    return populatePromise.then(function(d) {
                        if (!d || !d.success) {
                            showSnackbar((d && d.error) || 'Purpose auto-populate failed.', 'error');
                        } else {
                            var updated = typeof d.updated === 'number' ? d.updated : 0;
                            if (updated > 0) {
                                showSnackbar('Populated purposes for ' + updated + ' vendor(s).', 'success');
                            } else if (!(d.resolved && d.resolved.length)) {
                                showSnackbar('No purposes could be resolved for these vendors.', 'info');
                            }
                        }
                        return loadCalculatorData().then(function() {
                            if (d && Array.isArray(d.resolved) && d.resolved.length
                                && typeof window.applyPurposeLookupResultsToUi === 'function') {
                                window.applyPurposeLookupResultsToUi(d.resolved);
                            }
                        });
                    }).then(function() {
                        if (postProjectCreateFlow.hasPriorProjects) {
                            openPostCreatePurposeModal();
                        } else {
                            runVendorRedundancyStep();
                        }
                    }).catch(function() {
                        showSnackbar('Could not finish purpose setup.', 'error');
                        if (postProjectCreateFlow.hasPriorProjects) {
                            openPostCreatePurposeModal();
                        } else {
                            runVendorRedundancyStep();
                        }
                    }).finally(function() {
                        hideAiPopulateLoader();
                    });
                }).catch(function() {
                    showSnackbar('Could not finish purpose setup.', 'error');
                    continueAfterTour();
                }).finally(function() {
                    postProjectCreateFlow.purposeCheckInFlight = false;
                });
            }

            function advancePostProjectCreateFlow() {
                if (!postProjectCreateFlow.active) return;
                var step = postProjectCreateFlow.step;
                if (step === 'purpose_check' || (step === 'purposes' && postProjectCreateFlow.purposeCheckInFlight)) return;
                if (step === 'upload' || step === 'upload_waiting_import' || step === 'qbo' || step === 'qbo_waiting_sync'
                    || step === 'qbo_connect' || step === 'qbo_range' || step === 'qbo_import') {
                    clearPostCreateFlowSessionStorage();
                    postProjectCreateFlow.importCompleted = postProjectCreateFlow.importCompleted
                        || step === 'qbo_import'
                        || step === 'qbo_waiting_sync';
                    proceedToPurposeOrInvite();
                    return;
                }
                if (step === 'purpose' || step === 'purpose_done' || step === 'purposes') {
                    if (postProjectCreateFlow.hasPriorProjects) {
                        openPostCreateChatsModal();
                    } else {
                        runVendorRedundancyStep();
                    }
                    return;
                }
                if (step === 'chats') {
                    runVendorRedundancyStep();
                    return;
                }
                if (step === 'review') {
                    startWizardCoachTour();
                    return;
                }
                if (step === 'tour') {
                    finishWizardTour();
                    return;
                }
                if (step === 'invite' || step === 'invite_open') {
                    openSetupCompleteModal();
                }
            }

            function endPostProjectCreateFlow() {
                if (!postProjectCreateFlow.active) return;
                postProjectCreateFlow.active = false;
                postProjectCreateFlow.step = null;
                postProjectCreateFlow.importCompleted = false;
                postProjectCreateFlow.purposeCheckInFlight = false;
                postProjectCreateFlow.postCreateCsvImportInFlight = false;
                postProjectCreateFlow.visibleSteps = [];
                clearPostCreateFlowSessionStorage();
                closeWizardCoach();
                hideWizardProgressBar();
                closeAppModal('appModalPostCreateUpload');
                closeAppModal('appModalPostCreatePurpose');
                closeAppModal('appModalPostCreateChats');
                closeAppModal('appModalPostCreateInvite');
                closeAppModal('appModalVendorRedundancy');
                closeAppModal('appModalSetupComplete');
                resetPostCreatePurposeModal();
                resetPostCreateChatsModal();
                var form = document.getElementById('projectWizardForm');
                if (form) form.reset();
                var uploadRadio = document.getElementById('projectWizardDataModeUpload');
                if (uploadRadio) uploadRadio.checked = true;
                var startDateInput = document.getElementById('projectWizardStartDate');
                if (startDateInput && !startDateInput.value) {
                    startDateInput.value = new Date().toISOString().slice(0, 10);
                }
            }

            function beginWizardAtNameStep() {
                postProjectCreateFlow.active = true;
                postProjectCreateFlow.projectId = 0;
                postProjectCreateFlow.projectName = '';
                postProjectCreateFlow.importCompleted = false;
                postProjectCreateFlow.hasPriorProjects = false;
                postProjectCreateFlow.vendorCount = 0;
                postProjectCreateFlow.orgMemberCount = typeof TEAM_MEMBERS_COUNT === 'number' ? TEAM_MEMBERS_COUNT : 1;
                fetchHasPriorProjects().finally(function() {
                    setWizardStep('name');
                });
            }

            function startPostProjectCreateFlow(opts) {
                opts = opts || {};
                postProjectCreateFlow.active = true;
                postProjectCreateFlow.projectId = parseInt(opts.projectId, 10) || 0;
                postProjectCreateFlow.projectName = opts.projectName || '';
                postProjectCreateFlow.dataMode = opts.dataMode || 'qbo_after';
                postProjectCreateFlow.previousActiveProjectId = parseInt(opts.previousActiveProjectId, 10) || 0;
                postProjectCreateFlow.importCompleted = false;
                postProjectCreateFlow.purposeCheckInFlight = false;
                postProjectCreateFlow.postCreateCsvImportInFlight = false;
                postProjectCreateFlow.orgMemberCount = typeof TEAM_MEMBERS_COUNT === 'number' ? TEAM_MEMBERS_COUNT : 1;
                fetchHasPriorProjects().then(function() {
                    if (postProjectCreateFlow.dataMode === 'qbo_after' || postProjectCreateFlow.dataMode === 'upload_after') {
                        if (QBO_ORG_CONNECTED) {
                            openPostCreateQboSyncModal();
                        } else {
                            setWizardStep('qbo_connect');
                            setPostCreateSubtitle('postCreateUploadSubtitle');
                            refreshPostCreateQboButtons();
                            openAppModal('appModalPostCreateUpload');
                        }
                    } else {
                        proceedToPurposeOrInvite();
                    }
                });
            }

            function resumePostCreateQboSyncFromOAuth() {
                var stored = loadPostCreateFlowFromSessionStorage();
                <?php
                $qboResumeFromSession = null;
                if (!empty($_SESSION['qbo_resume_wizard']) && is_array($_SESSION['qbo_resume_wizard'])) {
                    $qboResumeFromSession = $_SESSION['qbo_resume_wizard'];
                    unset($_SESSION['qbo_resume_wizard']);
                }
                ?>
                var phpResume = <?php echo json_encode($qboResumeFromSession, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
                var params = new URLSearchParams(window.location.search);
                var shouldResume = params.get('resume_qbo_sync') === '1' || (phpResume && phpResume.project_id);
                if (!shouldResume) return;

                QBO_ORG_CONNECTED = true;
                var projectId = (phpResume && phpResume.project_id) || (stored && stored.projectId) || 0;
                var projectName = (phpResume && phpResume.project_name) || (stored && stored.projectName) || '';
                var prevId = (stored && stored.previousActiveProjectId) || 0;
                if (!projectId) return;

                postProjectCreateFlow.active = true;
                postProjectCreateFlow.projectId = parseInt(projectId, 10) || 0;
                postProjectCreateFlow.projectName = projectName || '';
                postProjectCreateFlow.previousActiveProjectId = parseInt(prevId, 10) || 0;
                postProjectCreateFlow.dataMode = 'qbo_after';
                postProjectCreateFlow.importCompleted = false;
                showWizardProgressBar();

                var syncActive = postJson({ action: 'project_set_active', project_id: postProjectCreateFlow.projectId })
                    .then(function(d) {
                        if (d && d.success) {
                            currentActiveProjectId = postProjectCreateFlow.projectId;
                        }
                    })
                    .catch(function() {});

                syncActive.then(function() {
                    return Promise.all([loadProjectsIntoMenu(), fetchHasPriorProjects()]);
                }).then(function() {
                    openPostCreateQboSyncModal();
                    if (window.history && window.history.replaceState) {
                        var url = new URL(window.location.href);
                        url.searchParams.delete('qbo');
                        url.searchParams.delete('resume_qbo_sync');
                        window.history.replaceState({}, document.title, url.pathname + url.search + url.hash);
                    }
                });
            }

            function openQboConnectConsent(opts) {
                opts = opts || {};
                var box = document.getElementById('qboConnectConsentCheckbox');
                var submit = document.getElementById('qboConnectConsentSubmit');
                var resume = document.getElementById('qboConnectResumeWizard');
                var projectId = document.getElementById('qboConnectProjectId');
                var projectName = document.getElementById('qboConnectProjectName');
                if (!box || !submit) return;
                if (resume) resume.value = opts.resumeWizard ? '1' : '';
                if (projectId) projectId.value = opts.projectId ? String(opts.projectId) : '';
                if (projectName) projectName.value = opts.projectName || '';
                box.checked = false;
                submit.disabled = true;
                submit.textContent = opts.reconnect ? 'Reconnect QuickBooks' : 'Connect to QuickBooks';
                openAppModal('appModalQboConnectConsent');
                var body = document.querySelector('#appModalQboConnectConsent .app-modal-body');
                if (body) body.scrollTop = 0;
            }

            function initQboConnectConsent() {
                var box = document.getElementById('qboConnectConsentCheckbox');
                var submit = document.getElementById('qboConnectConsentSubmit');
                var form = document.getElementById('qboConnectConsentForm');
                var settingsBtn = document.getElementById('qboSettingsConnectBtn');
                if (box && submit) {
                    box.addEventListener('change', function() {
                        submit.disabled = !box.checked;
                    });
                }
                if (form) {
                    form.addEventListener('submit', function(e) {
                        if (!box || !box.checked) {
                            e.preventDefault();
                            return;
                        }
                        var resume = document.getElementById('qboConnectResumeWizard');
                        if (resume && resume.value === '1' && postProjectCreateFlow.active) {
                            savePostCreateFlowToSessionStorage();
                        }
                    });
                }
                if (settingsBtn) {
                    settingsBtn.addEventListener('click', function() {
                        openQboConnectConsent({
                            reconnect: settingsBtn.getAttribute('data-reconnect') === '1'
                        });
                    });
                }
                document.querySelectorAll('#qboConnectConsentForm a[href="#qboSaverFeatures"], #qboConnectConsentForm a[data-open-qbo-features]').forEach(function(link) {
                    link.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        openAppModal('appModalQboSaverFeatures');
                    });
                });
            }

            function initPostProjectCreateFlow() {
                var skipBtn = document.getElementById('postCreateUploadSkipBtn');
                var connectBtn = document.getElementById('postCreateQboConnectBtn');
                var syncBtn = document.getElementById('postCreateQboSyncBtn');
                if (connectBtn) {
                    connectBtn.addEventListener('click', function() {
                        if (!postProjectCreateFlow.active) return;
                        openQboConnectConsent({
                            resumeWizard: true,
                            projectId: postProjectCreateFlow.projectId || 0,
                            projectName: postProjectCreateFlow.projectName || ''
                        });
                    });
                }
                if (syncBtn) {
                    syncBtn.addEventListener('click', function() {
                        if (!postProjectCreateFlow.active) return;
                        var projectId = postProjectCreateFlow.projectId || 0;
                        var ensureActive = projectId
                            ? postJson({ action: 'project_set_active', project_id: projectId }).then(function(d) {
                                if (d && d.success) currentActiveProjectId = projectId;
                            }).catch(function() {})
                            : Promise.resolve();
                        ensureActive.then(function() {
                            openPostCreateQboSyncModal();
                        });
                    });
                }
                if (skipBtn) {
                    skipBtn.addEventListener('click', function() {
                        closeAppModal('appModalPostCreateUpload');
                        advancePostProjectCreateFlow();
                    });
                }

                var purposeContinue = document.getElementById('postCreatePurposeContinueBtn');
                var purposeProceed = document.getElementById('postCreatePurposeProceedBtn');
                if (purposeContinue) {
                    purposeContinue.addEventListener('click', function() {
                        var purposeRadio = document.querySelector('input[name="postCreateCopyPurposes"]:checked');
                        if (!purposeRadio) {
                            showSnackbar('Choose Yes or No to continue.', 'error');
                            return;
                        }
                        postProjectCreateFlow.copyPurposes = purposeRadio.value === 'yes';
                        if (!postProjectCreateFlow.copyPurposes) {
                            closeAppModal('appModalPostCreatePurpose');
                            advancePostProjectCreateFlow();
                            return;
                        }
                        populateWizardSourceSelect('postCreatePurposeSource').then(function(ok) {
                            if (!ok) {
                                showSnackbar('No other projects are available to copy from.', 'info');
                                closeAppModal('appModalPostCreatePurpose');
                                advancePostProjectCreateFlow();
                                return;
                            }
                            var questionsBlock = document.getElementById('postCreatePurposeQuestionsBlock');
                            var sourceBlock = document.getElementById('postCreatePurposeSelectBlock');
                            if (questionsBlock) questionsBlock.style.display = 'none';
                            if (sourceBlock) sourceBlock.style.display = 'grid';
                            purposeContinue.style.display = 'none';
                            if (purposeProceed) purposeProceed.style.display = '';
                        });
                    });
                }
                if (purposeProceed) {
                    purposeProceed.addEventListener('click', function() {
                        var select = document.getElementById('postCreatePurposeSource');
                        var fromId = select ? parseInt(select.value, 10) : 0;
                        if (!fromId) {
                            showSnackbar('Select a source project.', 'error');
                            return;
                        }
                        var toId = postProjectCreateFlow.projectId;
                        purposeProceed.disabled = true;
                        postJson({
                            action: 'copy_project_purposes',
                            from_project_id: fromId,
                            to_project_id: toId,
                            include_blank_purposes: 0
                        }).then(function(d) {
                            if (!d || !d.success) {
                                throw new Error((d && d.error) || 'Could not overwrite purposes.');
                            }
                            var matched = parseInt(d.matched || 0, 10) || 0;
                            showSnackbar('Overwrote purposes for ' + matched + ' matching vendor(s).', 'success');
                            return loadCalculatorData();
                        }).then(function() {
                            closeAppModal('appModalPostCreatePurpose');
                            advancePostProjectCreateFlow();
                        }).catch(function(err) {
                            showSnackbar(err && err.message ? err.message : 'Copy failed.', 'error');
                        }).finally(function() {
                            purposeProceed.disabled = false;
                        });
                    });
                }

                var chatsContinue = document.getElementById('postCreateChatsContinueBtn');
                var chatsProceed = document.getElementById('postCreateChatsProceedBtn');
                if (chatsContinue) {
                    chatsContinue.addEventListener('click', function() {
                        var chatRadio = document.querySelector('input[name="postCreateCopyChats"]:checked');
                        if (!chatRadio) {
                            showSnackbar('Choose Yes or No to continue.', 'error');
                            return;
                        }
                        postProjectCreateFlow.copyChats = chatRadio.value === 'yes';
                        if (!postProjectCreateFlow.copyChats) {
                            closeAppModal('appModalPostCreateChats');
                            advancePostProjectCreateFlow();
                            return;
                        }
                        populateWizardSourceSelect('postCreateChatsSource').then(function(ok) {
                            if (!ok) {
                                showSnackbar('No other projects are available to copy from.', 'info');
                                closeAppModal('appModalPostCreateChats');
                                advancePostProjectCreateFlow();
                                return;
                            }
                            var questionsBlock = document.getElementById('postCreateChatsQuestionsBlock');
                            var sourceBlock = document.getElementById('postCreateChatsSelectBlock');
                            if (questionsBlock) questionsBlock.style.display = 'none';
                            if (sourceBlock) sourceBlock.style.display = 'grid';
                            chatsContinue.style.display = 'none';
                            if (chatsProceed) chatsProceed.style.display = '';
                        });
                    });
                }
                if (chatsProceed) {
                    chatsProceed.addEventListener('click', function() {
                        var select = document.getElementById('postCreateChatsSource');
                        var fromId = select ? parseInt(select.value, 10) : 0;
                        if (!fromId) {
                            showSnackbar('Select a source project.', 'error');
                            return;
                        }
                        chatsProceed.disabled = true;
                        postJson({
                            action: 'copy_project_chats',
                            from_project_id: fromId,
                            to_project_id: postProjectCreateFlow.projectId
                        }).then(function(d) {
                            if (!d || !d.success) {
                                throw new Error((d && d.error) || 'Could not copy chats.');
                            }
                            var msgs = parseInt(d.copied_messages || 0, 10) || 0;
                            var vendors = parseInt(d.matched_vendors || 0, 10) || 0;
                            showSnackbar('Copied ' + msgs + ' chat message(s) across ' + vendors + ' vendor(s).', 'success');
                            return loadCalculatorData();
                        }).then(function() {
                            closeAppModal('appModalPostCreateChats');
                            advancePostProjectCreateFlow();
                        }).catch(function(err) {
                            showSnackbar(err && err.message ? err.message : 'Copy failed.', 'error');
                        }).finally(function() {
                            chatsProceed.disabled = false;
                        });
                    });
                }

                var reviewContinue = document.getElementById('wizardReviewContinueBtn');
                if (reviewContinue) {
                    reviewContinue.addEventListener('click', function() {
                        closeAppModal('appModalVendorRedundancy');
                        advancePostProjectCreateFlow();
                    });
                }

                var coachNext = document.getElementById('wizardCoachNextBtn');
                var coachSkip = document.getElementById('wizardCoachSkipBtn');
                if (coachNext) {
                    coachNext.addEventListener('click', function() {
                        postProjectCreateFlow.tourIndex = (postProjectCreateFlow.tourIndex || 0) + 1;
                        renderWizardCoachStep();
                    });
                }
                if (coachSkip) {
                    coachSkip.addEventListener('click', function() {
                        finishWizardTour();
                    });
                }
                window.addEventListener('resize', function() {
                    var overlay = document.getElementById('wizardCoachOverlay');
                    if (overlay && overlay.classList.contains('is-open')) {
                        renderWizardCoachStep();
                    }
                });

                var inviteNo = document.getElementById('postCreateInviteNoBtn');
                var inviteYes = document.getElementById('postCreateInviteYesBtn');
                if (inviteNo) {
                    inviteNo.addEventListener('click', function() {
                        closeAppModal('appModalPostCreateInvite');
                        openSetupCompleteModal();
                    });
                }
                if (inviteYes) {
                    inviteYes.addEventListener('click', function() {
                        closeAppModal('appModalPostCreateInvite');
                        postProjectCreateFlow.step = 'invite_open';
                        updateWizardProgressBar();
                        openAppModal('appModalMembersInvite');
                    });
                }
                var setupDoneBtn = document.getElementById('setupCompleteDoneBtn');
                if (setupDoneBtn) {
                    setupDoneBtn.addEventListener('click', function() {
                        closeAppModal('appModalSetupComplete');
                        endPostProjectCreateFlow();
                        showSnackbar('Project setup complete.', 'success');
                    });
                }

                document.querySelectorAll('[data-open-modal="appModalProjectWizard"]').forEach(function(el) {
                    el.addEventListener('click', function() {
                        beginWizardAtNameStep();
                    });
                });
            }
