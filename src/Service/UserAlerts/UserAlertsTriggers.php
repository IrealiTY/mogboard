<?php

namespace App\Service\UserAlerts;

use App\Entity\UserAlert;
use App\Entity\UserAlertEvents;
use App\Service\Common\Mog;
use App\Service\GameData\GameServers;
use Carbon\Carbon;

/**
 * todo - optimise and refactor this, a lot of code duplication
 */
class UserAlertsTriggers extends UserAlerts
{
    const TIME_FORMAT = 'F j, Y, g:i a';
    
    /**
     * @var array[]
     */
    private $triggered = [];
    
    /**
     * Trigger alerts, intended to be called from commands
     */
    public function trigger(bool $patrons = false)
    {
        $this->console->writeln("Triggering Alerts");
    
        // grab all alerts
        $alerts = $this->getAllByPatronStatus($patrons);
        
        /** @var UserAlert $alert */
        foreach ($alerts as $alert) {
            $this->console->writeln("- Alert: <comment>{$alert->getName()}</comment> by <info>{$alert->getUser()->getUsername()}</info>");
            $this->console->writeln("--> Trigger Condition: <comment>{$alert->getTriggerOptionFormula()}</comment>");
            $this->console->writeln("--> Communication: <comment>". ($alert->isNotifiedViaDiscord() ? 'Discord' : 'None') ."</comment>");
            
            // check if the alert has been sent recently, wait the delay
            $timeout = $alert->getTriggerLastSent() + $alert->getTriggerDelay();
            if ($timeout > time()) {
                $this->console->writeln('--> Skipping as alert was triggered recently.');
                continue;
            }
            
            //
            // handle server
            //
            $dc         = GameServers::getDataCenter($alert->getServer());
            $dcServers  = GameServers::getDataCenterServers($alert->getServer());
            $servers    = $alert->isTriggerDataCenter() ? $dcServers : [ $alert->getServer() ];
            $this->console->writeln("--> Data Center: <info>{$dc}</info>: ". implode(', ', $servers));
            
            // grab market data
            $this->console->writeln("--> Getting market info");
            $market = $this->companion->getByServers($servers, $alert->getItemId());
            
            
            // todo check when prices last changed
            // - if this user is a patron user and the prices are older than a few minutes
            //   it will query companion directly.
            if ($patrons) {
            
            }
            
            
            // check triggers
            $this->console->writeln("--> Checking Triggers");
            foreach ($market as $server => $data) {
                // \App\Entity\UserAlert::TRIGGERS
                switch($alert->getTriggerOption()) {
                    case 100:
                    case 110:
                    case 120:
                        $this->triggerPricePerUnit($alert, $data->Prices);
                        break;
    
                    case 200:
                    case 210:
                    case 220:
                        $this->triggerPriceTotal($alert, $data->Prices);
                        break;
    
                    case 300:
                    case 310:
                    case 320:
                        $this->triggerSingleStockQuantity($alert, $data->Prices);
                        break;
    
                    case 400:
                    case 410:
                    case 420:
                        $this->triggerTotalStockQuantity($alert, $data->Prices);
                        break;
                        
                    case 600:
                    case 700:
                    case 800:
                        $this->triggerNameMatches($alert, $data->Prices, $data->History);
                        break;
                }
            }
    
            // if alerts, send them
            if ($this->triggered) {
                $alert
                    ->incrementTriggersSent()
                    ->setTriggerLastSent(time());
                
                $event = new UserAlertEvents();
                $event
                    ->setUserId($alert->getUser()->getId())
                    ->setUserAlert($alert)
                    ->setData($this->triggered);
    
                $this->em->persist($alert);
                $this->em->persist($event);
                $this->em->flush();
                
                // send discord msg
                // todo - this should be optional
                $triggers = implode("\n", $this->triggered);
                $message  = "**MOGBOARD TRIGGER: {$alert->getName()}** `{$alert->getTriggerOptionFormula()}` \n{$triggers}";
                Mog::aymeric($message, $alert->getUser()->getSsoDiscordId());
                
                // todo - implement email
    
                // reset
                $this->triggered = [];
            }
        }
    }
    
    /**
     * Price Per Unit triggers
     */
    private function triggerPricePerUnit(UserAlert $userAlert, array $prices)
    {
        $option = $userAlert->getTriggerOption();
        $value  = $userAlert->getTriggerValue();
        
        foreach ($prices as $price) {
            if (
                $option === 100 && $price->PricePerUnit > $value ||
                $option === 110 && $price->PricePerUnit < $value ||
                $option === 120 && $price->PricePerUnit == $value
            ) {
                $this->formatPricePerUnit($price);
                break;
            }
        }
    }
    
    /**
     * Format the text for triggerPricePerUnit
     */
    private function formatPricePerUnit(\stdClass $price)
    {
        // build visual string
        $string = sprintf(
            "(PRICE PER UNIT) Price: %s x %s (%s) - HQ: %s - Retainer: %s (%s) - Date Added: %s UTC",
            $price->Quantity,
            $price->PricePerUnit,
            $price->PriceTotal,
            $price->IsHQ ? 'Yes' : 'No',
            $price->RetainerName,
            $this->gamedata->getTown($price->TownID)['Name'],
            Carbon::createFromTimestamp($price->Added)->format(self::TIME_FORMAT)
        );
    
        $this->triggered[] = $string;
    }
    
    /**
     * Price Total triggers
     */
    private function triggerPriceTotal(UserAlert $userAlert, array $prices)
    {
        $option = $userAlert->getTriggerOption();
        $value  = $userAlert->getTriggerValue();
        
        foreach ($prices as $price) {
            if (
                $option === 200 && $price->PriceTotal > $value ||
                $option === 210 && $price->PriceTotal < $value ||
                $option === 220 && $price->PriceTotal == $value
            ) {
                $this->formatPriceTotal($price);
                break;
            }
        }
    }
    
    /**
     * Format the text for triggerPricePerUnit
     */
    private function formatPriceTotal(\stdClass $price)
    {
        // build visual string
        $string = sprintf(
            "(PRICE TOTAL) Price: %s x %s (%s) - HQ: %s - Retainer: %s (%s) - Date Added: %s UTC",
            $price->Quantity,
            $price->PricePerUnit,
            $price->PriceTotal,
            $price->IsHQ ? 'Yes' : 'No',
            $price->RetainerName,
            $this->gamedata->getTown($price->TownID)['Name'],
            Carbon::createFromTimestamp($price->Added)->format(self::TIME_FORMAT)
        );
        
        $this->triggered[] = $string;
    }
    
    /**
     * Single stock Quantity
     */
    private function triggerSingleStockQuantity(UserAlert $userAlert, array $prices)
    {
        $option = $userAlert->getTriggerOption();
        $value  = $userAlert->getTriggerValue();
        
        foreach ($prices as $price) {
            if (
                $option === 300 && $price->PriceTotal > $value ||
                $option === 310 && $price->PriceTotal < $value ||
                $option === 320 && $price->PriceTotal == $value
            ) {
                $this->formatSingleStockQuantity($price);
                break;
            }
        }
    }
    
    /**
     * Format the text for triggerSingleStockQuantity
     */
    private function formatSingleStockQuantity(\stdClass $price)
    {
        // build visual string
        $string = sprintf(
            "(SINGLE STOCK QUANTITY) Price: %s x %s (%s) - HQ: %s - Retainer: %s (%s) - Date Added: %s UTC",
            $price->Quantity,
            $price->PricePerUnit,
            $price->PriceTotal,
            $price->IsHQ ? 'Yes' : 'No',
            $price->RetainerName,
            $this->gamedata->getTown($price->TownID)['Name'],
            Carbon::createFromTimestamp($price->Added)->format(self::TIME_FORMAT)
        );
        
        $this->triggered[] = $string;
    }
    
    /**
     * Total stock Quantity
     */
    private function triggerTotalStockQuantity(UserAlert $userAlert, array $prices)
    {
        $option = $userAlert->getTriggerOption();
        $value  = $userAlert->getTriggerValue();
        
        $totalStock = 0;
        foreach ($prices as $price) {
            $totalStock += $price->Quantity;
        }
    
        if (
            $option === 400 && $totalStock > $value ||
            $option === 410 && $totalStock < $value ||
            $option === 420 && $totalStock == $value
        ) {
            $this->formatTotalStockQuantity($price);
            return;
        }
    }
    
    /**
     * Format the text for triggerTotalStockQuantity
     */
    private function formatTotalStockQuantity(\stdClass $price)
    {
        // build visual string
        $string = sprintf(
            "(TOTAL STOCK QUANTITY) Price: %s x %s (%s) - HQ: %s - Retainer: %s (%s) - Date Added: %s UTC",
            $price->Quantity,
            $price->PricePerUnit,
            $price->PriceTotal,
            $price->IsHQ ? 'Yes' : 'No',
            $price->RetainerName,
            $this->gamedata->getTown($price->TownID)['Name'],
            Carbon::createFromTimestamp($price->Added)->format(self::TIME_FORMAT)
        );
        
        $this->triggered[] = $string;
    }
    
    /**
     * Price Name match triggers
     */
    private function triggerNameMatches(UserAlert $userAlert, array $prices, array $history)
    {
        $option = $userAlert->getTriggerOption();
        $value  = strtolower($userAlert->getTriggerValue());
    
        foreach ($prices as $price) {
            if ($option === 600 && strtolower($price->RetainerName) == $value) {
                $this->formatRetainerNameMatch($price);
                break;
            }
            
            if ($option === 800 && strtolower($price->CreatorSignatureName) == $value) {
                $this->formatCreatorSignatureNameMatch($price);
                break;
            }
        }
        
        foreach ($history as $event) {
            // we only care about purchases AFTER the alert was created.
            $withinTime = $event->PurchaseDate > $userAlert->getAdded();
            
            if ($option === 700 && $withinTime && strtolower($event->CharacterName) == $value) {
                $this->formatCharacterBuyerNameMatch($event);
                break;
            }
        }
    }
    
    /**
     * Format the text for triggerNameMatches
     */
    private function formatRetainerNameMatch(\stdClass $price)
    {
        // build visual string
        $string = sprintf(
            "(RETAINER NAME MATCH) Name: %s - Price: %s x %s (%s) - HQ: %s - Retainer: %s (%s) - Date Added: %s UTC",
            $price->RetainerName,
            $price->Quantity,
            $price->PricePerUnit,
            $price->PriceTotal,
            $price->IsHQ ? 'Yes' : 'No',
            $price->RetainerName,
            $this->gamedata->getTown($price->TownID)['Name'],
            Carbon::createFromTimestamp($price->Added)->format(self::TIME_FORMAT)
        );
        
        $this->triggered[] = $string;
    }
    
    /**
     * Format the text for triggerNameMatches
     */
    private function formatCreatorSignatureNameMatch(\stdClass $price)
    {
        // build visual string
        $string = sprintf(
            "(CREATOR SIGNATURE NAME) Name: %s - Price: %s x %s (%s) - HQ: %s - Retainer: %s (%s) - Date Added: %s UTC",
            $price->CreatorSignatureName,
            $price->Quantity,
            $price->PricePerUnit,
            $price->PriceTotal,
            $price->IsHQ ? 'Yes' : 'No',
            $price->RetainerName,
            $this->gamedata->getTown($price->TownID)['Name'],
            Carbon::createFromTimestamp($price->Added)->format(self::TIME_FORMAT)
        );
        
        $this->triggered[] = $string;
    }
    
    /**
     * Format the text for triggerNameMatches
     */
    private function formatCharacterBuyerNameMatch(\stdClass $price)
    {
        // build visual string
        $string = sprintf(
            "(CHARACTER BUYER) Name: %s - Price: %s x %s (%s) - HQ: %s - Date Purchased: %s UTC - Date Added: %s UTC",
            $price->CharacterName,
            $price->Quantity,
            $price->PricePerUnit,
            $price->PriceTotal,
            $price->IsHQ ? 'Yes' : 'No',
            Carbon::createFromTimestamp($price->PurchaseDate)->format(self::TIME_FORMAT),
            Carbon::createFromTimestamp($price->Added)->format(self::TIME_FORMAT)
        );
        
        $this->triggered[] = $string;
    }
}