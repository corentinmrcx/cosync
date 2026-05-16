import './styles/app.css';

import Alpine from 'alpinejs';
import { inscriptionForm } from './js/inscription-form.js';

Alpine.data('inscriptionForm', inscriptionForm);

window.Alpine = Alpine;
Alpine.start();
