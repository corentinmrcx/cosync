<?php declare(strict_types=1);

namespace App\Tests\Service\Drive;

use App\Service\Drive\PendingUploadQueue;
use PHPUnit\Framework\TestCase;

/**
 * La file décide de ce qui part sur Drive après la réponse HTTP. Un identifiant qui n'y
 * entre pas, ou qui en sort deux fois, se traduit par un PDF jamais archivé ou par un
 * récapitulatif régénéré inutilement.
 */
final class PendingUploadQueueTest extends TestCase
{
    public function testChaqueFileEstIndependante(): void
    {
        $queue = new PendingUploadQueue();

        $queue->enqueueDocumentSignature(1);
        $queue->enqueueAttestation(2);
        $queue->enqueueDirigeantAttestation('uuid-dirigeant');
        $queue->enqueueAttestationCle(7);
        $queue->enqueueAttestationCleRecap(3);

        self::assertSame([1], $queue->flushDocumentSignatures());
        self::assertSame([2], $queue->flushAttestations());
        self::assertSame(['uuid-dirigeant'], $queue->flushDirigeantAttestations());
        self::assertSame([7], $queue->flushAttestationsCle());
        self::assertSame([3], $queue->flushAttestationCleRecaps());
    }

    /** Le vidage est définitif : un second passage ne doit pas rejouer les uploads. */
    public function testUneFileVideeNeRendPlusRien(): void
    {
        $queue = new PendingUploadQueue();
        $queue->enqueueDocumentSignature(1);

        self::assertSame([1], $queue->flushDocumentSignatures());
        self::assertSame([], $queue->flushDocumentSignatures());
    }

    public function testPlusieursSignaturesDansUneMemeRequeteSontToutesArchivees(): void
    {
        $queue = new PendingUploadQueue();

        $queue->enqueueDocumentSignature(1);
        $queue->enqueueDocumentSignature(2);
        $queue->enqueueDocumentSignature(3);

        self::assertSame([1, 2, 3], $queue->flushDocumentSignatures());
    }

    /**
     * Le récapitulatif des détenteurs est reconstruit depuis la base : le régénérer une
     * fois suffit, même si plusieurs dirigeants signent dans la même requête.
     */
    public function testLeRecapitulatifDesClesNEstRegenereQuUneFoisParSaison(): void
    {
        $queue = new PendingUploadQueue();

        $queue->enqueueAttestationCleRecap(1);
        $queue->enqueueAttestationCleRecap(1);
        $queue->enqueueAttestationCleRecap(2);

        self::assertSame([1, 2], $queue->flushAttestationCleRecaps());
    }
}
