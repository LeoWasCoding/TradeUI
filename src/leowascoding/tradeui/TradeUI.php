<?php

namespace leowascoding\tradeui;

use pocketmine\plugin\PluginBase;
use pocketmine\player\Player;
use pocketmine\utils\Config;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\Server;
use pocketmine\item\VanillaItems;
use muqsit\invmenu\InvMenu;
use muqsit\invmenu\InvMenuHandler;
use muqsit\invmenu\type\InvMenuTypeIds;
use muqsit\invmenu\transaction\InvMenuTransaction;
use muqsit\invmenu\transaction\InvMenuTransactionResult;
use pocketmine\event\Listener;
use pocketmine\event\inventory\InventoryCloseEvent;
use pocketmine\scheduler\Task;

class TradeUI extends PluginBase implements Listener {
    /** @var Config */
    private Config $config;
    /** @var array<string, TradeSession> */
    private array $sessions = [];

    public function onEnable(): void {
        if (!InvMenuHandler::isRegistered()) {
            InvMenuHandler::register($this);
        }
        @mkdir($this->getDataFolder());
        $this->saveDefaultConfig();
        $this->config = $this->getConfig();
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if (!$sender instanceof Player) return false;
        if (strtolower($command->getName()) !== 'trade') return false;

        if (count($args) < 1) {
            $sender->sendMessage("§cUsage: /trade <player>");
            return true;
        }

        $target = Server::getInstance()->getPlayerExact($args[0]);
        if (!$target instanceof Player || !$target->isOnline()) {
            $sender->sendMessage("§cPlayer not found.");
            return true;
        }

        if ($target->getName() === $sender->getName()) {
            $sender->sendMessage("§cYou cannot trade with yourself.");
            return true;
        }

        $radius = (float)$this->config->get("trade-radius", 10);
        if ($sender->getPosition()->distance($target->getPosition()) > $radius) {
            $sender->sendMessage("§cPlayer is too far. Get within $radius blocks.");
            return true;
        }

        if (isset($this->sessions[$sender->getName()]) || isset($this->sessions[$target->getName()])) {
            $sender->sendMessage("§cEither you or the target is already in a trade.");
            return true;
        }

        $session = new TradeSession($this, $sender, $target);
        $this->sessions[$sender->getName()] = $session;
        $this->sessions[$target->getName()] = $session;
        $session->open();
        return true;
    }

    public function endSession(TradeSession $session): void {
        unset($this->sessions[$session->getPlayer1()->getName()]);
        unset($this->sessions[$session->getPlayer2()->getName()]);
    }

    public function onInventoryClose(InventoryCloseEvent $event): void {
        $player = $event->getPlayer();
        if ($player instanceof Player && isset($this->sessions[$player->getName()])) {
            $this->sessions[$player->getName()]->cancel("closed inventory");
        }
    }
}

class TradeSession {
    private TradeUI $plugin;
    private Player $p1;
    private Player $p2;
    private InvMenu $menu1;
    private InvMenu $menu2;
    private array $offered1 = [];
    private array $offered2 = [];
    private bool $confirmed1 = false;
    private bool $confirmed2 = false;
    private int $confirmSlot = 53;

    public function __construct(TradeUI $plugin, Player $p1, Player $p2) {
        $this->plugin = $plugin;
        $this->p1 = $p1;
        $this->p2 = $p2;

        $this->menu1 = InvMenu::create(InvMenuTypeIds::DOUBLE_CHEST);
        $this->menu2 = InvMenu::create(InvMenuTypeIds::DOUBLE_CHEST);
        $this->menu1->setName("Trade with {$p2->getName()}");
        $this->menu2->setName("Trade with {$p1->getName()}");

        $this->menu1->setListener(function(InvMenuTransaction $transaction) {
            return $this->onInventoryClick($transaction, $this->p1, $this->p2, $this->offered1);
        });
        $this->menu2->setListener(function(InvMenuTransaction $transaction) {
            return $this->onInventoryClick($transaction, $this->p2, $this->p1, $this->offered2);
        });
    }

    public function open(): void {
        $this->menu1->send($this->p1);
        $this->menu2->send($this->p2);
        $this->p1->sendMessage("§aTrade request accepted. Add items and click the green wool to confirm.");
        $this->p2->sendMessage("§aTrade request accepted. Add items and click the green wool to confirm.");

        $timeout = (int)$this->plugin->getConfig()->get("trade-timeout", 600) * 20;
        $this->plugin->getScheduler()->scheduleDelayedTask(new class($this) extends Task {
            private TradeSession $session;
            public function __construct(TradeSession $session) {
                $this->session = $session;
            }
            public function onRun(): void {
                $this->session->cancel("timed out");
            }
        }, $timeout);

        $confirmItem = VanillaItems::WOOL()->setMeta(5)->setCustomName("§aConfirm trade");
        $this->menu1->getInventory()->setItem($this->confirmSlot, $confirmItem);
        $this->menu2->getInventory()->setItem($this->confirmSlot, $confirmItem);
    }

    private function onInventoryClick(InvMenuTransaction $transaction, Player $owner, Player $other, array &$offered): InvMenuTransactionResult {
        $slot = $transaction->getAction()->getSlot();
        if ($slot === $this->confirmSlot) {
            $this->setConfirmed($owner);
            return $transaction->discard();
        }
        if ($slot < 0 || $slot > 52) {
            return $transaction->discard();
        }
        $item = $transaction->getOut()->getItem(0);
        $offered[$slot] = clone $item;
        return $transaction->continue();
    }

    private function setConfirmed(Player $player): void {
        if ($player === $this->p1) {
            $this->confirmed1 = true;
        } else {
            $this->confirmed2 = true;
        }
        $player->sendMessage("§aYou confirmed. Waiting for the other player...");
        if ($this->confirmed1 && $this->confirmed2) {
            $this->complete();
        }
    }

    public function complete(): void {
        foreach ($this->offered1 as $item) {
            $this->p2->getInventory()->addItem($item);
        }
        foreach ($this->offered2 as $item) {
            $this->p1->getInventory()->addItem($item);
        }
        $this->p1->sendMessage("§aTrade completed successfully.");
        $this->p2->sendMessage("§aTrade completed successfully.");
        $this->menu1->getPlayer()->removeCurrentWindow();
        $this->menu2->getPlayer()->removeCurrentWindow();
        $this->plugin->endSession($this);
    }

    public function cancel(string $reason): void {
        foreach ($this->offered1 as $item) {
            $this->p1->getInventory()->addItem($item);
        }
        foreach ($this->offered2 as $item) {
            $this->p2->getInventory()->addItem($item);
        }
        $this->p1->sendMessage("§cTrade canceled ({$reason}). Items returned.");
        $this->p2->sendMessage("§cTrade canceled ({$reason}). Items returned.");
        $this->menu1->getPlayer()->removeCurrentWindow();
        $this->menu2->getPlayer()->removeCurrentWindow();
        $this->plugin->endSession($this);
    }

    public function getPlayer1(): Player {
        return $this->p1;
    }

    public function getPlayer2(): Player {
        return $this->p2;
    }
}