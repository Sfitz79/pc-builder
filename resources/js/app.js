import Alpine from 'alpinejs';
import './builder';
import './checkout';
import './pctg-intro';
import './pctg-page-reveal';
import './pctg-count-up';
import './pctg-landing-demos';

window.Alpine = Alpine;

// three.js is ~600 kB and is only needed once a customer opens the 3D panel.
// A static import put the whole library in the main bundle, so every visitor
// paid for the 3D view on first paint whether or not they ever opened it.
// The dynamic import keeps it in its own chunk, fetched on demand.
//
// mountPcViewport is still exposed on window because the Alpine store calls
// it by name, and Alpine is already running by the time a panel is opened.
let viewportModulePromise = null;

window.loadPcViewportModule = function () {
    if (!viewportModulePromise) {
        viewportModulePromise = import('./pc-viewport')
            .then((m) => {
                window.mountPcViewport = m.mountPcViewport;
                return m;
            })
            .catch((e) => {
                // Reset so a later attempt can retry, and say why it failed
                // rather than leaving a Show button that silently does nothing.
                viewportModulePromise = null;
                window.dispatchEvent(new CustomEvent('pctg:viewport-load-failed', {
                    detail: { message: e && e.message ? e.message : 'The 3D view could not be loaded.' },
                }));
                throw e;
            });
    }
    return viewportModulePromise;
};

Alpine.start();
