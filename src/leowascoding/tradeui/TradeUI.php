<?php

namespace leowascoding\tradeui;

use pocketmine\plugin\PluginBase;
use pocketmine\player\Player;
use pocketmine\utils\Config;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\Server;
use muqsit\invmenu\InvMenu;
use muqsit\invmenu\InvMenuHandler;
use muqsit\invmenu\transaction\InvMenuTransaction;
use muqsit\invmenu\transaction\InvMenuTransactionResult;
use pocketmine\event\Listener;
use pocketmine\event\inventory\InventoryCloseEvent;
use pocketmine\item\StringToItemParser;
use pocketmine\scheduler\Task;
use pocketmine\scheduler\TaskHandler;

class TradeUI extends PluginBase implements Listener {
    private Config $config;
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
    private bool $countdownActive = false;
    private ?TaskHandler $countdownTaskHandler = null;

    public function __construct(TradeUI $plugin, Player $p1, Player $p2) {
        $this->plugin = $plugin;
        $this->p1 = $p1;
        $this->p2 = $p2;

        $this->menu1 = InvMenu::create(InvMenu::TYPE_DOUBLE_CHEST);
        $this->menu2 = InvMenu::create(InvMenu::TYPE_DOUBLE_CHEST);
        $this->menu1->setName("Trade with {$p2->getName()}");
        $this->menu2->setName("Trade with {$p1->getName()}");

        $this->menu1->setListener(fn(InvMenuTransaction $t) => $this->onInventoryClick($t, $this->p1, $this->p2, $this->offered1, $this->confirmed1));
        $this->menu2->setListener(fn(InvMenuTransaction $t) => $this->onInventoryClick($t, $this->p2, $this->p1, $this->offered2, $this->confirmed2));
    }

    public function open(): void {
        $this->menu1->send($this->p1);
        $this->menu2->send($this->p2);

        $this->p1->sendMessage("§aTrade request accepted. Add items to the left side and click the green wool to confirm.");
        $this->p2->sendMessage("§aTrade request accepted. Add items to the left side and click the green wool to confirm.");

        $timeout = (int)$this->plugin->getConfig()->get("trade-timeout", 600) * 20;
        $this->plugin->getScheduler()->scheduleDelayedTask(new class($this) extends Task {
            private TradeSession $session;
            public function __construct(TradeSession $session) { $this->session = $session; }
            public function onRun(): void { $this->session->cancel("timed out"); }
        }, $timeout);

        $this->updateConfirmButtons();
        $this->updateGlassPanes();
    }

    private function updateConfirmButtons(): void {
        $confirmItem = StringToItemParser::getInstance()->parse("lime_wool")->setCustomName("§aConfirm trade");
        $unconfirmItem = StringToItemParser::getInstance()->parse("red_wool")->setCustomName("§cUnconfirm trade");

        $this->menu1->getInventory()->setItem(53, $this->confirmed1 ? $unconfirmItem : $confirmItem);
        $this->menu2->getInventory()->setItem(53, $this->confirmed2 ? $unconfirmItem : $confirmItem);
    }

    private function updateGlassPanes(): void {
        $pane = StringToItemParser::getInstance()->parse("red_stained_glass_pane")->setCustomName("§c");
        $this->menu1->getInventory()->setItem(27, $pane);
        $this->menu2->getInventory()->setItem(27, $pane);
    }

    private function onInventoryClick(InvMenuTransaction $transaction, Player $owner, Player $other, array &$offered, bool &$confirmed): InvMenuTransactionResult {
        $slot = $transaction->getAction()->getSlot();

        if ($slot === 53) {
            if ($confirmed) {
                $confirmed = false;
                $this->countdownCancel();
                $owner->sendMessage("§cYou unconfirmed the trade.");
                $other->sendMessage("§c{$owner->getName()} unconfirmed the trade.");
                $this->updateConfirmButtons();
                $this->resetCountdownState();
                return $transaction->discard();
            } else {
                $confirmed = true;
                $owner->sendMessage("§aYou confirmed. Waiting for the other player...");
                $this->updateConfirmButtons();
                if ($this->confirmed1 && $this->confirmed2) {
                    $this->startCountdown();
                }
                return $transaction->discard();
            }
        }

        if ($slot === 27 || $slot > 26 || $slot < 0) {
            return $transaction->discard();
        }

        if ($this->countdownActive) {
            $owner->sendMessage("§cYou cannot modify items while the trade countdown is active. You can only unconfirm.");
            return $transaction->discard();
        }

        $item = clone $transaction->getOut();

        if ($item->isNull()) {
            unset($offered[$slot]);
        } else {
            $offered[$slot] = $item;
        }

        $targetInventory = ($other === $this->p1) ? $this->menu1->getInventory() : $this->menu2->getInventory();
        if ($item->isNull()) {
            $targetInventory->clear($slot + 28);
        } else {
            $targetInventory->setItem($slot + 28, $item);
        }

        $this->confirmed1 = false;
        $this->confirmed2 = false;
        $this->updateConfirmButtons();
        $this->resetCountdownState();

        return $transaction->continue();
    }

    private function startCountdown(): void {
        $this->countdownActive = true;
        $this->p1->sendMessage("§aBoth players confirmed. Trade will complete in 3 seconds. You can unconfirm to cancel.");
        $this->p2->sendMessage("§aBoth players confirmed. Trade will complete in 3 seconds. You can unconfirm to cancel.");

        $count = 3;
        $plugin = $this->plugin;

        $this->countdownTaskHandler = $plugin->getScheduler()->scheduleRepeatingTask(new class($this, $count) extends Task {
            private TradeSession $session;
            private int $countdown;

            public function __construct(TradeSession $session, int $countdown) {
                $this->session = $session;
                $this->countdown = $countdown;
            }

            public function onRun(): void {
                if ($this->countdown <= 0) {
                    $this->session->complete();
                    $this->session->setCountdownInactive();
                    $this->getHandler()->cancel();
                    return;
                }
                $this->session->getPlayer1()->sendMessage("§eCompleting trade in {$this->countdown}...");
                $this->session->getPlayer2()->sendMessage("§eCompleting trade in {$this->countdown}...");
                $this->countdown--;
            }
        }, 20);
    }

    private function countdownCancel(): void {
        if ($this->countdownActive && $this->countdownTaskHandler !== null) {
            $this->countdownTaskHandler->cancel();
            $this->countdownActive = false;
            $this->countdownTaskHandler = null;
            $this->p1->sendMessage("§cTrade countdown cancelled.");
            $this->p2->sendMessage("§cTrade countdown cancelled.");
        }
    }

    private function resetCountdownState(): void {
        $this->countdownActive = false;
        $this->countdownTaskHandler = null;
    }

    public function setCountdownInactive(): void {
        $this->countdownActive = false;
        $this->countdownTaskHandler = null;
    }

    public function getPlayer1(): Player {
        return $this->p1;
    }

    public function getPlayer2(): Player {
        return $this->p2;
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
        $this->p1->removeWindow($this->menu1->getInventory());
        $this->p2->removeWindow($this->menu2->getInventory());
        $this->plugin->endSession($this);
    }

    public function cancel(string $reason): void {
        $this->countdownCancel();
        foreach ($this->offered1 as $item) {
            $this->p1->getInventory()->addItem($item);
        }
        foreach ($this->offered2 as $item) {
            $this->p2->getInventory()->addItem($item);
        }
        $this->p1->sendMessage("§cTrade canceled ({$reason}). Items returned.");
        $this->p2->sendMessage("§cTrade canceled ({$reason}). Items returned.");
        $this->p1->removeWindow($this->menu1->getInventory());
        $this->p2->removeWindow($this->menu2->getInventory());
        $this->plugin->endSession($this);
    }

    public function getPlayer1(): Player { return $this->p1; }
    public function getPlayer2(): Player { return $this->p2; }
}
