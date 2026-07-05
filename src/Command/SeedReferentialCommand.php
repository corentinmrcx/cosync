<?php declare(strict_types=1);

namespace App\Command;

use App\Entity\Category;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:seed-referential', description: 'Initialise les codes catégories FFF (à lancer une seule fois au setup)')]
class SeedReferentialCommand extends Command
{
    // Codes de catégories FFF — stables entre les saisons.
    // Les années de naissance associées ne sont pas stockées ici :
    // elles sont lues directement depuis la colonne "Sous catégorie" du XLSX FootClubs.
    private const CATEGORIES = [
        ['code' => 'U6',      'label' => 'U6 (Baby Foot)',    'ecole' => true],
        ['code' => 'U7',      'label' => 'U7',                'ecole' => true],
        ['code' => 'U7F',     'label' => 'U7 Féminin',        'ecole' => true],
        ['code' => 'U8',      'label' => 'U8',                'ecole' => true],
        ['code' => 'U8F',     'label' => 'U8 Féminin',        'ecole' => true],
        ['code' => 'U9',      'label' => 'U9',                'ecole' => true],
        ['code' => 'U9F',     'label' => 'U9 Féminin',        'ecole' => true],
        ['code' => 'U10',     'label' => 'U10',               'ecole' => true],
        ['code' => 'U10F',    'label' => 'U10 Féminin',       'ecole' => true],
        ['code' => 'U11',     'label' => 'U11',               'ecole' => true],
        ['code' => 'U11F',    'label' => 'U11 Féminin',       'ecole' => true],
        ['code' => 'U12',     'label' => 'U12',               'ecole' => true],
        ['code' => 'U12F',    'label' => 'U12 Féminin',       'ecole' => true],
        ['code' => 'U13',     'label' => 'U13',               'ecole' => true],
        ['code' => 'U13F',    'label' => 'U13 Féminin',       'ecole' => true],
        ['code' => 'U14',     'label' => 'U14',               'ecole' => false],
        ['code' => 'U14F',    'label' => 'U14 Féminin',       'ecole' => false],
        ['code' => 'U15',     'label' => 'U15',               'ecole' => false],
        ['code' => 'U15F',    'label' => 'U15 Féminin',       'ecole' => false],
        ['code' => 'U16',     'label' => 'U16',               'ecole' => false],
        ['code' => 'U16F',    'label' => 'U16 Féminin',       'ecole' => false],
        ['code' => 'U17',     'label' => 'U17',               'ecole' => false],
        ['code' => 'U17F',    'label' => 'U17 Féminin',       'ecole' => false],
        ['code' => 'U18',     'label' => 'U18',               'ecole' => false],
        ['code' => 'U18F',    'label' => 'U18 Féminin',       'ecole' => false],
        ['code' => 'U19',     'label' => 'U19',               'ecole' => false],
        ['code' => 'U19F',    'label' => 'U19 Féminin',       'ecole' => false],
        ['code' => 'SENIOR',     'label' => 'Senior',            'ecole' => false],
        ['code' => 'SENIORF',    'label' => 'Senior Féminin',    'ecole' => false],
        ['code' => 'VETERAN',    'label' => 'Vétéran',           'ecole' => false],
        ['code' => 'FOOTLOISIR', 'label' => 'Foot Loisir',       'ecole' => false],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->seedCategories($io);

        $this->em->flush();

        $io->success('Catégories FFF initialisées.');
        return Command::SUCCESS;
    }

    private function seedCategories(SymfonyStyle $io): void
    {
        $repo = $this->em->getRepository(Category::class);

        foreach (self::CATEGORIES as $data) {
            if ($repo->findOneBy(['code' => $data['code']]) !== null) {
                continue;
            }

            $cat = new Category();
            $cat->setCode($data['code']);
            $cat->setLabel($data['label']);
            $cat->setIsEcoleFoot($data['ecole']);

            $this->em->persist($cat);
            $io->writeln(sprintf('  Catégorie ajoutée : %s', $data['code']));
        }
    }

}
