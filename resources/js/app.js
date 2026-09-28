import Alpine from 'alpinejs';
import './builder';
import './checkout';
import './pctg-intro';
import './pctg-page-reveal';
import './pctg-count-up';
import './pctg-landing-demos';
import { mountPcViewport } from './pc-viewport';

window.Alpine = Alpine;
window.mountPcViewport = mountPcViewport;

Alpine.start();
