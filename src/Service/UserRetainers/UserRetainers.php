<?php

namespace App\Service\UserRetainers;

use App\Entity\UserRetainer;
use App\Exceptions\GeneralJsonException;
use App\Exceptions\UnauthorisedRetainerOwnershipException;
use App\Repository\UserRetainerRepository;
use App\Service\Companion\Companion;
use App\Service\GameData\GameServers;
use App\Service\ThirdParty\Discord\Discord;
use App\Service\User\Users;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\HttpFoundation\Request;
use XIVAPI\XIVAPI;

class UserRetainers
{
    // the maximum amount of time an unconfirmed retainer may exist.
    const MAX_LURK_TIME = (60 * 60 * 2);
    
    /** @var EntityManagerInterface */
    private $em;
    /** @var Users */
    private $users;
    /** @var Companion */
    private $companion;
    /** @var UserRetainerRepository */
    private $repository;
    /** @var ConsoleOutput */
    private $console;

    public function __construct(
        EntityManagerInterface $em,
        Users $users,
        Companion $companion
    ) {
        $this->em         = $em;
        $this->users      = $users;
        $this->companion  = $companion;
        $this->repository = $em->getRepository(UserRetainer::class);
        $this->console    = new ConsoleOutput();
    }

    /**
     * Get a retainer
     */
    public function get(string $unique, bool $confirmed = false)
    {
        return $this->repository->findOneBy([
            'uniq'    => $unique,
            'confirmed' => $confirmed,
        ]);
    }
    
    /**
     * Get a retainer via its slug
     */
    public function getSlugRetainer(string $slug)
    {
        return $this->repository->findOneBy([
            'slug' => $slug,
        ]);
    }
    
    /**
     * Get a retainer via its apiRetainerId
     */
    public function getCompanionApiRetainer(string $apiRetainerId)
    {
        return $this->repository->findOneBy([
            'apiRetainerId' => $apiRetainerId,
        ]);
    }

    /**
     * Add a new character to the site
     */
    public function add(Request $request)
    {
        $name   = trim($request->get('name'));
        $server = ucwords(trim($request->get('server')));
        $itemId = (int)trim($request->get('itemId'));
        
        $unique = UserRetainer::unique($name, $server);

        if ($this->get($unique, true)) {
            throw new GeneralJsonException('Retainer already exists and is confirmed.');
        }

        $retainer = (new UserRetainer())
            ->setUser($this->users->getUser())
            ->setName($name)
            ->setServer($server)
            ->setUniq($unique)
            ->setSlug()
            ->setConfirmItem($itemId)
            ->setConfirmPrice(mt_rand(1000000, 15000000));

        $this->save($retainer);
        return true;
    }

    /**
     * Confirm a characters ownership
     */
    public function confirm(UserRetainer $retainer)
    {
        // enforce the user is online
        $this->users->getUser();
        
        // if the retainer was updated recently, don't do anything
        if ($retainer->isRecent()) {
            return false;
        }

        // confirmation variables
        $server    = $retainer->getServer();
        $itemId    = $retainer->getConfirmItem();
        $itemPrice = $retainer->getConfirmPrice();
        $name      = $retainer->getName();
        
        // query market
        $xivapi = new XIVAPI();
        $market = $xivapi->_private->itemPrices(
            getenv('XIVAPI_COMPANION_KEY'),
            $itemId,
            $server
        );
        
        // find listing
        $found = false;
        foreach ($market->entries as $entry) {
            if ($entry->sellRetainerName == $name && $entry->sellPrice == $itemPrice) {
                $found = true;
                break;
            }
        }
        
        if ($found === false) {
            $retainer->setUpdated(time());
            $this->save($retainer);
            return false;
        }

        // confirm ownership and save
        $retainer
            ->setConfirmPrice(0)
            ->setConfirmItem(0)
            ->setConfirmed(true)
            ->setUpdated(time());

        $this->save($retainer);
        return true;
    }

    /**
     * Save a new or existing alert
     */
    public function save(UserRetainer $userRetainer)
    {
        $this->em->persist($userRetainer);
        $this->em->flush();
        return true;
    }
    
    /**
     * Delete a retainer
     */
    public function delete(UserRetainer $userRetainer)
    {
        if ($userRetainer->getUser() !== $this->users->getUser()) {
            throw new UnauthorisedRetainerOwnershipException();
        }
        
        $this->em->remove($userRetainer);
        $this->em->flush();
        return true;
    }
    
    /**
     * This will remove retainers which have not been verified for more than 2 hours.
     */
    public function removeLurkingRetainers()
    {
        $retainers = $this->repository->findBy([ 'confirmed' => false ], [ 'added' => 'asc' ]);
        $deadline  = time() - self::MAX_LURK_TIME;
        
        /** @var UserRetainer $retainer */
        foreach ($retainers as $retainer) {
            if ($deadline > $retainer->getAdded()) {
                $this->em->remove($retainer);
                $this->em->flush();
            }
        }
    }
    
    /**
     * This will link retainers to the companion api ID
     */
    public function linkCompanionApiIdentities()
    {
        $console = new ConsoleOutput();
        $console->writeln("Finding retainers on the companion database.");
        
        $retainers = $this->repository->findBy(
            [
                'confirmed' => true,
                'apiRetainerId' => null,
            ],
            [
                'updated' => 'desc',
            ],
            50
        );
        
        $console->writeln(count($retainers) .' retainers to find.');
        $console = $console->section();
        
        /** @var UserRetainer $retainer */
        foreach ($retainers as $retainer) {
            $console->overwrite("Finding: {$retainer->getName()} - {$retainer->getServer()}");
            
            // find retainer in companion table
            $companionData = $this->repository->findRetainerInCompanionTable(
                $retainer->getName(),
                GameServers::getServerId($retainer->getServer())
            );
            
            // if it isn't null, assign it!
            if ($companionData !== null) {
                $retainer->setApiRetainerId($companionData['id']);
            }
            
            $retainer->setUpdated(time());
            $this->em->persist($retainer);
            $this->em->flush();
        }
    }
}
