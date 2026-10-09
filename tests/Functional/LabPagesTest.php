<?php

namespace App\Tests\Functional;

use App\Entity\Novel;
use App\Generation\JobRunner;
use App\Message\RunGenerationJob;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Parcours complet par l'interface, avec le fournisseur simulé.
 */
final class LabPagesTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->executeStatement('TRUNCATE novel, messenger_messages RESTART IDENTITY CASCADE');
    }

    public function testFullJourneyThroughTheInterface(): void
    {
        $this->client->request('GET', '/romans');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Mode simulation');

        // 1. Demande, avec l'exemple prérempli.
        $crawler = $this->client->request('GET', '/romans/nouveau?exemple=1');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Enregistrer la demande')->form();
        self::assertSame('Le Phare des marées inverses', $form['novel_brief[title]']->getValue());
        $this->client->submit($form);
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Le Phare des marées inverses');

        // 2. Plan.
        $this->client->submit($crawler->selectButton('Générer la proposition d\'histoire et le plan')->form());
        $this->drain();
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.badge', 'Plan à valider');
        self::assertSelectorExists('#plan');

        // 3. Modification puis validation du plan.
        $crawler = $this->client->request('GET', $crawler->selectLink('Modifier le plan')->link()->getUri());
        $form = $crawler->selectButton('Enregistrer le plan')->form();
        $form['plan[synopsis]'] = 'Synopsis retouché par Corantin.';
        $this->client->submit($form);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('#plan', 'Synopsis retouché par Corantin.');
        $this->client->submit($crawler->selectButton('Valider le plan')->form());
        $crawler = $this->client->followRedirect();

        // 4. Chapitres : lancement du premier, puis enchaînement automatique après chaque acceptation humaine.
        $this->client->submit($crawler->selectButton('Générer le chapitre 1')->form());
        $this->drain();
        for ($n = 1; $n <= 3; ++$n) {
            $crawler = $this->client->request('GET', '/romans/1/chapitres/'.$n);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.prose', 'simulateur');
            $this->client->submit($crawler->selectButton('Accepter ce chapitre')->form());
            $this->drain();
            $crawler = $this->client->request('GET', '/romans/1');
        }
        self::assertSelectorTextContains('h1 .badge', 'Terminé');

        // 5. Correction d'un passage.
        $crawler = $this->client->request('GET', '/romans/1/chapitres/2');
        $form = $crawler->selectButton('Corriger ce passage')->form();
        $form['passage'] = 'Paragraphe 3 du chapitre 2.';
        $form['reason'] = 'Plus de tension.';
        $this->client->submit($form);
        $this->drain();
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.versions', 'Correction IA');
        self::assertSelectorTextContains('.versions', 'v1');

        // 6. Coûts et exports.
        $this->client->request('GET', '/romans/1/couts');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Contrôle de cohérence');
        $this->client->request('GET', '/romans/1/export.md');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/markdown; charset=UTF-8');
        $this->client->request('GET', '/romans/1/export.epub');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/epub+zip');

        // État JSON pour l'actualisation automatique.
        $this->client->request('GET', '/romans/1/etat');
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('fingerprint', json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    public function testActionsRequireValidToken(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $novel = new Novel('Sans jeton');
        $novel->setBrief(['chapterCount' => 3, 'targetWordsPerChapter' => 1000, 'genre' => 'x']);
        $em->persist($novel);
        $em->flush();

        $this->client->request('POST', '/romans/'.$novel->getId().'/plan/generer', ['_token' => 'faux']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'expiré');
        self::assertCount(0, self::getContainer()->get('messenger.transport.async')->getSent(), 'Aucune tâche lancée sans jeton valide.');
    }

    public function testFormRequiresOnlyThePremise(): void
    {
        $crawler = $this->client->request('GET', '/romans/nouveau');
        $form = $crawler->selectButton('Enregistrer la demande')->form();
        $form['novel_brief[premise]'] = 'Un chat détective enquête dans une bibliothèque hantée.';
        $this->client->submit($form);
        self::assertResponseRedirects();
    }

    private function drain(): void
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        $runner = self::getContainer()->get(JobRunner::class);
        while ($envelopes = iterator_to_array($transport->get())) {
            foreach ($envelopes as $envelope) {
                $transport->ack($envelope);
                /** @var RunGenerationJob $message */
                $message = $envelope->getMessage();
                $runner->run($message->jobId);
            }
        }
    }
}
