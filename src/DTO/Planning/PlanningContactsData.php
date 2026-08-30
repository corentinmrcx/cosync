<?php declare(strict_types=1);

namespace App\DTO\Planning;

use Symfony\Component\HttpFoundation\Request;

/**
 * Les personnes à joindre, imprimées au pied du flyer.
 *
 * Saisies dans des champs séparés — un nom, un téléphone — et non dans une zone de texte
 * libre : le pied du flyer part dans toutes les boîtes aux lettres du village, et une
 * ligne mal formée y reste imprimée. Le document met lui-même le séparateur.
 */
final class PlanningContactsData
{
    /** Deux suffisent : c'est ce que le club imprime, et trois ne tiendraient pas en largeur. */
    public const NOMBRE = 2;

    /** @param list<array{nom: string, telephone: string}> $contacts */
    private function __construct(
        public readonly array $contacts,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $noms = $request->request->all('contactNom');
        $telephones = $request->request->all('contactTelephone');

        $contacts = [];

        for ($i = 0; $i < self::NOMBRE; ++$i) {
            $nom = trim((string) ($noms[$i] ?? ''));
            $telephone = trim((string) ($telephones[$i] ?? ''));

            // Un téléphone sans nom ne dit à personne qui décroche : on écarte la ligne
            // plutôt que d'imprimer un numéro anonyme.
            if ($nom !== '') {
                $contacts[] = ['nom' => $nom, 'telephone' => $telephone];
            }
        }

        return new self($contacts);
    }

    /** Relit ce qui est stocké pour repeupler le formulaire. */
    public static function depuisTexte(?string $texte): self
    {
        $contacts = [];

        foreach (preg_split('/\r\n|\r|\n/', (string) $texte) ?: [] as $ligne) {
            $ligne = trim($ligne);

            if ($ligne === '') {
                continue;
            }

            [$nom, $telephone] = array_pad(explode(' — ', $ligne, 2), 2, '');
            $contacts[] = ['nom' => trim($nom), 'telephone' => trim($telephone)];
        }

        return new self(array_slice($contacts, 0, self::NOMBRE));
    }

    /** @return array{nom: string, telephone: string} */
    public function ligne(int $index): array
    {
        return $this->contacts[$index] ?? ['nom' => '', 'telephone' => ''];
    }

    /** Une ligne par contact, `Nom — téléphone`, ou null si aucun contact n'est renseigné. */
    public function versTexte(): ?string
    {
        $lignes = [];

        foreach ($this->contacts as $contact) {
            $lignes[] = $contact['telephone'] === ''
                ? $contact['nom']
                : $contact['nom'] . ' — ' . $contact['telephone'];
        }

        return $lignes === [] ? null : implode("\n", $lignes);
    }
}
