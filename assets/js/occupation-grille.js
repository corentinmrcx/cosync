/**
 * La grille d'occupation des terrains : glisser pour poser un créneau, cliquer pour le corriger.
 *
 * ⚠️ **Ce composant ne calcule aucune mise en page.** La position des blocs est décidée
 * côté serveur par `OccupationGrilleGeometrie` et posée en pourcentages dans le HTML, parce
 * que le même calcul sert au PDF remis à la mairie — DomPDF ne saurait pas rejouer une mise
 * en page JavaScript. Reprendre le placement ici donnerait deux moteurs qui divergeraient,
 * et un document papier qui ne ressemblerait plus à l'écran. C'est aussi la raison pour
 * laquelle aucune librairie d'agenda n'est embarquée : elles placent, mais côté navigateur
 * seulement, et elles raisonnent en dates là où cette grille n'en a pas.
 *
 * Le glissé, lui, est bien du ressort du navigateur : il traduit un geste en deux horaires,
 * et s'arrête là. C'est la modale qui enregistre, par un POST de formulaire ordinaire.
 *
 * @param {object} config
 * @param {string} config.urlNouveau URL de création, vide si le compte n'a pas le droit
 * @param {string} config.urlModifier URL de modification, `__ID__` à remplacer
 * @param {string} config.urlSupprimer URL de suppression, `__ID__` à remplacer
 * @param {number} config.debut première minute affichée par la grille
 * @param {number} config.fin dernière minute affichée
 * @param {string} config.terrainParDefaut identifiant du terrain proposé d'emblée
 */
export function occupationGrille(config) {
    /** Le pas de la grille, en minutes — le même que celui du service côté serveur. */
    const PAS = 15;

    /** Durée d'un créneau créé d'un simple clic, sans glissé : une séance ordinaire. */
    const DUREE_PAR_DEFAUT = 90;

    /** En deçà, le geste est un clic et non un glissé. */
    const GLISSE_MINIMALE = 30;

    const horaire = (minutes) => {
        const bornees = Math.min(Math.max(minutes, config.debut), config.fin);
        return String(Math.floor(bornees / 60)).padStart(2, '0') + ':' + String(bornees % 60).padStart(2, '0');
    };

    return {
        modalOuverte: false,
        mode: 'creation',
        creneauId: null,
        tokenSupprimer: '',

        jour: 'lundi',
        heureDebut: '18:00',
        heureFin: '19:30',
        equipe: '',
        espace: config.terrainParDefaut,
        usage: 'entrainement',

        /** Glissé en cours : { jour, piste, ancre, debut, fin } en minutes depuis minuit. */
        glisse: null,

        get urlEnregistrer() {
            return this.mode === 'edition'
                ? config.urlModifier.replace('__ID__', String(this.creneauId))
                : config.urlNouveau;
        },

        get urlSupprimer() {
            return config.urlSupprimer.replace('__ID__', String(this.creneauId));
        },

        /**
         * L'aperçu suit le geste, aux mêmes pourcentages que les blocs enregistrés.
         *
         * ⚠️ Un **objet**, jamais une chaîne : `:style` avec une chaîne réécrit l'attribut
         * `style` entier et efface au passage le `display: none` que `x-show` venait d'y
         * poser. L'aperçu apparaissait alors dans les sept colonnes à la fois. Avec un
         * objet, Alpine ne touche qu'aux propriétés nommées.
         */
        get apercuStyle() {
            if (!this.glisse) {
                return {};
            }

            const amplitude = config.fin - config.debut;

            return {
                top: `${((this.glisse.debut - config.debut) * 100) / amplitude}%`,
                height: `${((this.glisse.fin - this.glisse.debut) * 100) / amplitude}%`,
            };
        },

        /* ── Glissé ── */

        commencerGlisse(event, jour) {
            // Un compte en lecture reçoit une URL vide : le geste ne mène nulle part.
            if (config.urlNouveau === '') {
                return;
            }

            // Le `pointerdown` d'un bloc remonte jusqu'à la piste : sans ce filtre, cliquer
            // un créneau pour le corriger ouvrirait d'abord la modale de création, que le
            // `click` du bloc écraserait aussitôt — un clignotement pour rien.
            if (event.target.closest('.occupation-bloc')) {
                return;
            }

            const piste = event.currentTarget;
            const minute = this.minuteSous(event, piste);

            this.glisse = { jour, piste, ancre: minute, debut: minute, fin: minute + PAS };
        },

        suivreGlisse(event) {
            if (!this.glisse) {
                return;
            }

            // Le pointeur peut sortir de la colonne : on continue de lire sa hauteur sur la
            // piste d'origine, sinon le créneau s'arrêterait au bord de l'écran.
            const minute = this.minuteSous(event, this.glisse.piste);

            this.glisse.debut = Math.min(this.glisse.ancre, minute);
            this.glisse.fin = Math.max(this.glisse.ancre, minute);
        },

        finirGlisse() {
            if (!this.glisse) {
                return;
            }

            const { jour, debut, fin } = this.glisse;
            this.glisse = null;

            // Un clic sec vaut une intention d'ajouter, pas un créneau d'un quart d'heure.
            const duree = fin - debut < GLISSE_MINIMALE ? DUREE_PAR_DEFAUT : fin - debut;

            this.ouvrirCreation();
            this.jour = jour;
            this.heureDebut = horaire(debut);
            this.heureFin = horaire(debut + duree);
        },

        /** La minute survolée, accrochée au quart d'heure. */
        minuteSous(event, piste) {
            const cadre = piste.getBoundingClientRect();
            const part = (event.clientY - cadre.top) / cadre.height;
            const brute = config.debut + part * (config.fin - config.debut);
            const accrochee = Math.round(brute / PAS) * PAS;

            return Math.min(Math.max(accrochee, config.debut), config.fin);
        },

        /* ── Modale ── */

        ouvrirCreation() {
            this.mode = 'creation';
            this.creneauId = null;
            this.tokenSupprimer = '';
            this.jour = 'lundi';
            this.heureDebut = '18:00';
            this.heureFin = '19:30';
            this.equipe = '';
            this.espace = config.terrainParDefaut;
            this.usage = 'entrainement';
            this.modalOuverte = true;
        },

        /** Le créneau est décrit par les attributs `data-` de son bloc : un seul balisage. */
        ouvrirEdition(element) {
            const donnees = element.dataset;

            this.mode = 'edition';
            this.creneauId = donnees.id;
            this.tokenSupprimer = donnees.token ?? '';
            this.jour = donnees.jour;
            this.heureDebut = donnees.debut;
            this.heureFin = donnees.fin;
            this.equipe = donnees.equipe ?? '';
            this.espace = donnees.espace;
            this.usage = donnees.usage;
            this.modalOuverte = true;
        },

        fermer() {
            this.modalOuverte = false;
        },
    };
}
