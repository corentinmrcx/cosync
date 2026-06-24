import './styles/app.css';
import './js/loader.js';

import Alpine from 'alpinejs';
import { inscriptionForm } from './js/inscription-form.js';
import { textCombobox } from './js/text-combobox.js';

Alpine.data('inscriptionForm', inscriptionForm);
Alpine.data('textCombobox', textCombobox);

window.Alpine = Alpine;
Alpine.start();
