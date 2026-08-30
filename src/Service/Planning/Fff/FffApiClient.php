<?php declare(strict_types=1);

namespace App\Service\Planning\Fff;

use App\Exception\FffApiException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface as HttpClientHttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Accès à l'API publique DOFA de la FFF. HTTP et rien d'autre : aucune règle métier ici.
 *
 * L'API est ouverte, sans jeton ni compte. Deux routes suffisent au planning :
 * `/api/clubs/{cl_no}` pour vérifier le rattachement, `/api/clubs/{cl_no}/matchs` pour le
 * calendrier. `cl_no` n'est **pas** le numéro d'affiliation du club — c'est l'identifiant
 * interne DOFA, celui qu'on lit dans l'URL de la fiche club sur le site fédéral.
 *
 * ⚠️ Ce n'est pas une API contractuelle : elle a déjà changé d'hôte deux fois et n'a plus
 * de documentation publique. Les erreurs sont donc traitées comme un état normal du
 * système, jamais comme un bug — cf. FffApiException.
 */
final class FffApiClient
{
    private const BASE_URL = 'https://api-dofa.fff.fr';

    /** L'API pagine à 30. Borne de sécurité : un club n'a pas 600 matchs dans une saison. */
    private const PAGES_MAX = 20;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%app.fff_timeout%')] private readonly float $timeout = 10.0,
    ) {}

    /**
     * Fiche d'un club, pour confirmer à l'admin qu'il a saisi le bon numéro.
     *
     * @return array<string, mixed>
     *
     * @throws FffApiException
     */
    public function club(int $clubNo): array
    {
        return $this->get(sprintf('/api/clubs/%d', $clubNo));
    }

    /**
     * Tous les matchs du club pour la saison en cours à la FFF, domicile et extérieur
     * mélangés — c'est au mapper de trier.
     *
     * @return list<array<string, mixed>>
     *
     * @throws FffApiException
     */
    public function matchs(int $clubNo): array
    {
        $tous = [];

        for ($page = 1; $page <= self::PAGES_MAX; ++$page) {
            $lot = $this->get(sprintf('/api/clubs/%d/matchs?page=%d', $clubNo, $page));

            if ($lot === []) {
                break;
            }

            foreach ($lot as $ligne) {
                if (is_array($ligne)) {
                    $tous[] = $ligne;
                }
            }
        }

        return $tous;
    }

    /**
     * @return array<mixed>
     *
     * @throws FffApiException
     */
    private function get(string $chemin): array
    {
        try {
            $response = $this->httpClient->request('GET', self::BASE_URL . $chemin, [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => $this->timeout,
                // Un cron ne doit jamais rester pendu sur un hôte qui ne répond plus.
                'max_duration' => $this->timeout * 3,
            ]);

            $statut = $response->getStatusCode();

            if ($statut !== 200) {
                throw new FffApiException(sprintf('L\'API FFF a répondu %d sur %s.', $statut, $chemin), $statut);
            }

            return $response->toArray();
        } catch (FffApiException $e) {
            throw $e;
        } catch (HttpClientHttpExceptionInterface $e) {
            throw new FffApiException(sprintf('L\'API FFF a répondu %d sur %s.', $e->getResponse()->getStatusCode(), $chemin), $e->getResponse()->getStatusCode(), $e);
        } catch (HttpExceptionInterface $e) {
            // Réseau coupé, DNS, TLS, timeout, ou corps illisible : aucun code HTTP à
            // rendre, et rien qui distingue une panne d'un changement d'API.
            throw new FffApiException('Impossible de joindre l\'API FFF : ' . $e->getMessage(), null, $e);
        } catch (\JsonException $e) {
            throw new FffApiException('L\'API FFF a renvoyé une réponse illisible.', null, $e);
        }
    }
}
