import './styles/app.css';
import './js/loader.js';

import Alpine from 'alpinejs';
import { inscriptionForm } from './js/inscription-form.js';
import { completionForm } from './js/completion-form.js';
import { dirigeantForm } from './js/dirigeant-form.js';
import { textCombobox } from './js/text-combobox.js';

Alpine.data('inscriptionForm', inscriptionForm);
Alpine.data('completionForm', completionForm);
Alpine.data('dirigeantForm', dirigeantForm);
Alpine.data('textCombobox', textCombobox);

window.Alpine = Alpine;
Alpine.start();
