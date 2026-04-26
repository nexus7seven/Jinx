<script>
    (function () {
        if (typeof window.initJinxLeadSearch === 'function') {
            return;
        }

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        window.initJinxLeadSearch = function initJinxLeadSearch(config) {
            const container = document.getElementById(config.containerId);
            const input = document.getElementById(config.inputId);
            const resultsEl = document.getElementById(config.resultsId);
            const minLength = Number(config.minLength || 2);
            const debounceMs = Number(config.debounceMs || 250);
            const searchUrl = String(config.searchUrl || '');
            const emptyClass = String(config.emptyClass || '');
            const resultClass = String(config.resultClass || '');
            const nameClass = String(config.nameClass || '');
            const metaClass = String(config.metaClass || '');
            const noResultsText = String(config.noResultsText || 'No matching leads found');

            if (!container || !input || !resultsEl || searchUrl === '') {
                return;
            }

            let debounceTimer = null;
            let requestSeq = 0;

            const hideResults = function () {
                resultsEl.style.display = 'none';
                resultsEl.innerHTML = '';
            };

            const showNoMatches = function () {
                resultsEl.innerHTML = '<div class="' + escapeHtml(emptyClass) + '">' + escapeHtml(noResultsText) + '</div>';
                resultsEl.style.display = 'block';
            };

            const renderResults = function (results) {
                if (!Array.isArray(results) || results.length === 0) {
                    showNoMatches();
                    return;
                }

                const html = results.map(function (item) {
                    const name = (item.name || '').toString();
                    const phone = (item.phone_number || '').toString().trim();
                    const vicidial = (item.vicidial_lead_id || '').toString().trim();
                    const metaParts = [];
                    if (phone !== '') metaParts.push('Phone: ' + phone);
                    if (vicidial !== '') metaParts.push('VICIdial: ' + vicidial);
                    const meta = metaParts.length ? metaParts.join(' · ') : 'Lead #' + item.id;

                    return '<a class="' + escapeHtml(resultClass) + '" href="' + escapeHtml(item.url) + '">'
                        + '<span class="' + escapeHtml(nameClass) + '">' + escapeHtml(name) + '</span>'
                        + '<span class="' + escapeHtml(metaClass) + '">' + escapeHtml(meta) + '</span>'
                        + '</a>';
                }).join('');

                resultsEl.innerHTML = html;
                resultsEl.style.display = 'block';
            };

            const runSearch = function () {
                const q = (input.value || '').trim();
                if (q.length < minLength) {
                    hideResults();
                    return;
                }

                const currentSeq = ++requestSeq;

                fetch(searchUrl + '?q=' + encodeURIComponent(q), {
                    headers: { 'Accept': 'application/json' },
                })
                    .then(function (r) { return r.ok ? r.json() : { results: [] }; })
                    .then(function (data) {
                        if (currentSeq !== requestSeq) return;
                        renderResults(data.results || []);
                    })
                    .catch(function () {
                        if (currentSeq !== requestSeq) return;
                        showNoMatches();
                    });
            };

            input.addEventListener('input', function () {
                if (debounceTimer) clearTimeout(debounceTimer);
                debounceTimer = setTimeout(runSearch, debounceMs);
            });

            document.addEventListener('click', function (event) {
                if (container.contains(event.target)) return;
                hideResults();
            });
        };
    })();
</script>
