import './styles/app.css';
import './js/loader.js';

import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import { inscriptionForm } from './js/inscription-form.js';
import { completionForm } from './js/completion-form.js';
import { dirigeantForm } from './js/dirigeant-form.js';
import { attestationCleForm } from './js/attestation-cle-form.js';
import { textCombobox } from './js/text-combobox.js';
import { initEditeurRiche } from './js/editeur-riche.js';
import { stockItemForm } from './js/stock-item-form.js';
import { stockMouvementModal } from './js/stock-mouvement-modal.js';
import { stockCorrectionModal } from './js/stock-correction-modal.js';
import { stockNoteModal } from './js/stock-note-modal.js';
import { confirmationPurge } from './js/confirmation-purge.js';
import { sommaireActif } from './js/sommaire-actif.js';
import { multiSelect } from './js/multi-select.js';
import { dirigeantPrefill } from './js/dirigeant-prefill.js';
import { selectListe } from './js/select-liste.js';
import { listeTriable } from './js/liste-triable.js';
import { dotationCibles } from './js/dotation-cibles.js';
import { ecoulementForm } from './js/ecoulement-form.js';

Alpine.data('inscriptionForm', inscriptionForm);
Alpine.data('completionForm', completionForm);
Alpine.data('dirigeantForm', dirigeantForm);
Alpine.data('attestationCleForm', attestationCleForm);
Alpine.data('textCombobox', textCombobox);
Alpine.data('stockItemForm', stockItemForm);
Alpine.data('stockMouvementModal', stockMouvementModal);
Alpine.data('stockCorrectionModal', stockCorrectionModal);
Alpine.data('stockNoteModal', stockNoteModal);
Alpine.data('confirmationPurge', confirmationPurge);
Alpine.data('sommaireActif', sommaireActif);
Alpine.data('multiSelect', multiSelect);
Alpine.data('selectListe', selectListe);
Alpine.data('listeTriable', listeTriable);
Alpine.data('dotationCibles', dotationCibles);
Alpine.data('ecoulementForm', ecoulementForm);

Alpine.plugin(collapse);

// Appelé depuis les écrans qui embarquent un éditeur riche.
window.initEditeurRiche = initEditeurRiche;
window.dirigeantPrefill = dirigeantPrefill;

window.Alpine = Alpine;
Alpine.start();
