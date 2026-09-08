<script>
(function initFinancialStatement() {
    const fsClientPayload = @json($fsClientPayload);
    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const financialStatementSaveUrl = @json($financialStatementSaveUrl);
    const card = document.getElementById('financialStatementCard');
    const statusEl = document.getElementById('financialStatementStatus');
    if (!card || !statusEl || !fsClientPayload) {
        return;
    }

    function fsFormatMoney(value) {
        const n = Number(value);
        if (!Number.isFinite(n)) {
            return '£0.00';
        }
        return '£' + n.toLocaleString('en-GB', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function fsComputeCap(band, adults, u16, s18) {
        const c = fsClientPayload.guidelineBands && fsClientPayload.guidelineBands[band];
        if (!c) {
            return 0;
        }
        const extraA = Math.max(0, adults - 1);
        const raw = Number(c.base_one_adult)
            + extraA * Number(c.additional_adult)
            + u16 * Number(c.child_under_16)
            + s18 * Number(c.child_16_18);

        return Math.round(raw * 100) / 100;
    }

    function getHousehold() {
        return {
            adults: parseInt(document.getElementById('fsAdults').value, 10),
            children_under_16: parseInt(document.getElementById('fsChildrenU16').value, 10),
            children_16_18: parseInt(document.getElementById('fsChildren1618').value, 10)
        };
    }

    function formatHouseholdForCapLabel(hh) {
        const a = hh.adults;
        const u = hh.children_under_16;
        const s = hh.children_16_18;
        const adultWord = a === 1 ? 'adult' : 'adults';
        const under16Word = u === 1 ? 'child' : 'children';
        const teenWord = s === 1 ? 'child' : 'children';
        return a + ' ' + adultWord
            + ', ' + u + ' ' + under16Word + ' under 16'
            + ', ' + s + ' ' + teenWord + ' aged 16–18';
    }

    function parseAmount(el) {
        const v = el.value.trim();
        if (v === '') {
            return 0;
        }
        const n = parseFloat(v);
        return Number.isFinite(n) ? Math.max(0, n) : 0;
    }

    function sectionTotal(sectionId) {
        let sum = 0;
        card.querySelectorAll('input[data-fs-section="' + sectionId + '"]').forEach(function (el) {
            sum += parseAmount(el);
        });
        return Math.round(sum * 100) / 100;
    }

    function incomeTotal() {
        let sum = 0;
        card.querySelectorAll('[data-fs-income]').forEach(function (el) {
            sum += parseAmount(el);
        });
        return Math.round(sum * 100) / 100;
    }

    function expenditureTotal() {
        let sum = 0;
        card.querySelectorAll('[data-fs-expenditure]').forEach(function (el) {
            sum += parseAmount(el);
        });
        return Math.round(sum * 100) / 100;
    }

    function refreshTotalsAndGuidelines() {
        const incomeTotalValue = incomeTotal();
        const expenditureTotalValue = expenditureTotal();
        const disposableTotalValue = Math.round((incomeTotalValue - expenditureTotalValue) * 100) / 100;

        const incomeSumEl = document.getElementById('fs-sum-income');
        if (incomeSumEl) {
            incomeSumEl.textContent = fsFormatMoney(incomeTotalValue);
        }

        const summaryIncomeEl = document.getElementById('fs-summary-income-total');
        if (summaryIncomeEl) {
            summaryIncomeEl.textContent = fsFormatMoney(incomeTotalValue);
        }

        const summaryExpenditureEl = document.getElementById('fs-summary-expenditure-total');
        if (summaryExpenditureEl) {
            summaryExpenditureEl.textContent = fsFormatMoney(expenditureTotalValue);
        }

        const summaryDisposableEl = document.getElementById('fs-summary-disposable-total');
        if (summaryDisposableEl) {
            summaryDisposableEl.textContent = fsFormatMoney(disposableTotalValue);
            summaryDisposableEl.style.color = disposableTotalValue < 0 ? '#fda4af' : '#e2e8f0';
        }

        const hh = getHousehold();
        const householdEl = document.getElementById('fs-overview-household');
        if (householdEl) {
            householdEl.textContent = formatHouseholdForCapLabel(hh);
        }

        const targetRaw = card.getAttribute('data-fs-target-di');
        const targetDi = targetRaw === null || targetRaw === '' ? null : Number(targetRaw);
        const varianceEl = document.getElementById('fs-overview-variance');
        if (varianceEl) {
            if (targetDi === null || !Number.isFinite(targetDi)) {
                varianceEl.textContent = '—';
                varianceEl.style.color = '#94a3b8';
            } else {
                const variance = Math.round((disposableTotalValue - targetDi) * 100) / 100;
                const sign = variance > 0 ? '+' : '';
                varianceEl.textContent = sign + fsFormatMoney(variance);
                varianceEl.style.color = variance < 0 ? '#fda4af' : '#86efac';
            }
        }

        const sfsWarnings = [];
        fsClientPayload.expenditure_sections.forEach(function (sec) {
            const sid = sec.id;
            const total = sectionTotal(sid);
            const sumEl = document.getElementById('fs-sum-' + sid);
            if (sumEl) {
                sumEl.textContent = fsFormatMoney(total);
            }

            const meta = document.getElementById('fs-meta-' + sid);
            if (!meta) {
                return;
            }

            const band = meta.getAttribute('data-fs-cap-band');
            const cap = fsComputeCap(band, hh.adults, hh.children_under_16, hh.children_16_18);
            const capStrong = meta.querySelector('[data-fs-cap-value]');
            if (capStrong) {
                capStrong.textContent = fsFormatMoney(cap);
            }

            const hhLabelEl = meta.querySelector('[data-fs-household-label]');
            if (hhLabelEl) {
                hhLabelEl.textContent = formatHouseholdForCapLabel(hh);
            }

            const pctWrap = meta.querySelector('[data-fs-pct-wrap]');
            const below65El = meta.querySelector('[data-fs-below65]');
            const overEl = meta.querySelector('[data-fs-over]');

            meta.style.borderColor = '#334155';
            meta.style.background = '#020617';

            if (cap <= 0) {
                if (pctWrap) {
                    pctWrap.textContent = '';
                }
                if (below65El) {
                    below65El.style.display = 'none';
                }
                if (overEl) {
                    overEl.style.display = 'none';
                }
                return;
            }

            const pct = total / cap * 100;
            const pctRounded = Math.round(pct * 10) / 10;
            if (pctWrap) {
                pctWrap.textContent = pctRounded + '% of guideline cap';
            }

            if (pct < 65) {
                if (below65El) {
                    below65El.style.display = 'block';
                    below65El.textContent = 'Below 65% of guideline — spending is low relative to cap.';
                }
                meta.style.borderColor = '#854d0e';
                meta.style.background = '#1c1917';
                sfsWarnings.push(sec.title + ' below 65% of cap');
            } else if (below65El) {
                below65El.style.display = 'none';
            }

            if (total > cap + 0.005) {
                if (overEl) {
                    overEl.style.display = 'block';
                    overEl.textContent = 'Exceeds guideline by ' + fsFormatMoney(total - cap) + '.';
                }
                meta.style.borderColor = '#991b1b';
                meta.style.background = '#2a1215';
                sfsWarnings.push(sec.title + ' exceeds cap');
            } else if (overEl) {
                overEl.style.display = 'none';
            }
        });

        const sfsEl = document.getElementById('fs-overview-sfs');
        if (sfsEl) {
            if (sfsWarnings.length === 0) {
                sfsEl.textContent = 'No SFS guideline warnings';
                sfsEl.style.color = '#86efac';
            } else {
                sfsEl.textContent = sfsWarnings.join(' · ');
                sfsEl.style.color = '#fde68a';
            }
        }
    }

    let fsSaveTimer = null;

    function collectPayload() {
        const household = getHousehold();
        const income = {};
        card.querySelectorAll('[data-fs-income]').forEach(function (el) {
            income[el.getAttribute('data-fs-income')] = parseAmount(el);
        });
        const expenditure = {};
        card.querySelectorAll('[data-fs-expenditure]').forEach(function (el) {
            expenditure[el.getAttribute('data-fs-expenditure')] = parseAmount(el);
        });
        return { household, income, expenditure };
    }

    async function saveFinancialStatement() {
        statusEl.textContent = 'Saving...';
        statusEl.style.color = '#fbbf24';
        try {
            const response = await fetch(financialStatementSaveUrl, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json'
                },
                body: JSON.stringify(collectPayload())
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error();
            }
            statusEl.textContent = 'Saved';
            statusEl.style.color = '#10b981';
        } catch (e) {
            statusEl.textContent = 'Save failed';
            statusEl.style.color = '#ef4444';
        }
    }

    function scheduleFinancialSave() {
        refreshTotalsAndGuidelines();
        if (fsSaveTimer) {
            clearTimeout(fsSaveTimer);
        }
        fsSaveTimer = setTimeout(saveFinancialStatement, 650);
    }

    card.addEventListener('input', function (e) {
        if (e.target.matches('[data-fs-income], [data-fs-expenditure]')) {
            scheduleFinancialSave();
        }
    });

    card.addEventListener('change', function (e) {
        if (e.target.matches('select[data-fs-household]')) {
            scheduleFinancialSave();
        }
    });

    const minimiseAllBtn = document.getElementById('fsMinimiseAllIe');
    if (minimiseAllBtn) {
        minimiseAllBtn.addEventListener('click', function () {
            card.querySelectorAll('details[data-fs-ie-card]').forEach(function (d) {
                d.removeAttribute('open');
            });
        });
    }

    refreshTotalsAndGuidelines();
})();
</script>
