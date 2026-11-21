// ============================================================
// ADMIN: INSTITUCIÓN → ESTADÍSTICAS (gráficos)
// ============================================================
// Variable esperada:
//   window.INST_CHART_DATA  (objeto con divisiones, roles, asistencia, pubs)

document.addEventListener('DOMContentLoaded', function() {
    if (typeof Chart === 'undefined') {
        console.warn('Chart.js no está cargado');
        return;
    }

    const CHART_DATA = window.INST_CHART_DATA || {};
    const chartColors = ['#2c74b3', '#7dd3fc', '#15803d', '#ca8a04', '#b91c1c', '#a855f7', '#0ea5e9', '#f472b6'];

    function crearBarra(id, cfg) {
        const c = document.getElementById(id); if (!c) return;
        new Chart(c, {
            type: 'bar',
            data: { labels: cfg.labels, datasets: [{ label: cfg.label || '', data: cfg.data, backgroundColor: chartColors, borderRadius: 6 }] },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: !!cfg.label, labels: { color: '#eaf2ff' } } },
                scales: {
                    x: { ticks: { color: '#cfe2ff', maxRotation: 60, minRotation: 0 }, grid: { color: 'rgba(255,255,255,0.05)' } },
                    y: { ticks: { color: '#cfe2ff' }, grid: { color: 'rgba(255,255,255,0.05)' }, beginAtZero: true },
                },
            }
        });
    }

    if (CHART_DATA.divisiones) crearBarra('chartDivisionesInst', { labels: CHART_DATA.divisiones.labels, data: CHART_DATA.divisiones.data, label: 'Alumnos' });
    if (CHART_DATA.roles)      crearBarra('chartRolesInst',      { labels: CHART_DATA.roles.labels,      data: CHART_DATA.roles.data,      label: 'Usuarios' });
    if (CHART_DATA.asistencia) crearBarra('chartAsistenciaInst', { labels: CHART_DATA.asistencia.labels, data: CHART_DATA.asistencia.data, label: 'Registros' });
    if (CHART_DATA.pubs)       crearBarra('chartPubsInst',       { labels: CHART_DATA.pubs.labels,       data: CHART_DATA.pubs.data,       label: 'Publicaciones' });
});