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
    private array $pendingRequests = [];

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

        if (count($args) < 2) {
            $sender->sendMessage("§cUsage: /trade <request|accept|deny> <player>");
            return true;
        }

        [$sub, $name] = [$args[0], $args[1]];
        $target = Server::getInstance()->getPlayerExact($name);
        if (!$target instanceof Player || !$target->isOnline()) {
            $sender->sendMessage("§cPlayer not found.");
            return true;
        }
        if ($sender->getName() === $target->getName()) {
            $sender->sendMessage("§cYou cannot trade with yourself.");
            return true;
        }

        switch (strtolower($sub)) {
            case 'request':
                $this->handleRequest($sender, $target);
                break;
            case 'accept':
                $this->handleAccept($sender, $target);
                break;
            case 'deny':
                $this->handleDeny($sender, $target);
                break;
            default:
                $sender->sendMessage("§cUnknown subcommand. Use request/accept/deny.");
        }
        return true;
    }

    private function handleRequest(Player $sender, Player $target): void {
        $key = strtolower($sender->getName()) . '>' . strtolower($target->getName());
        if (isset($this->pendingRequests[$key])) {
            $sender->sendMessage("§cYou have already sent a request to {$target->getName()}.");
            return;
        }
        $expires = time() + 30;
        $this->pendingRequests[$key] = $expires;
        $sender->sendMessage("§eTrade request sent to {$target->getName()}. Expires in 30s.");
        $target->sendMessage("§e{$sender->getName()} wants to trade with you. Type /trade accept {$sender->getName()} or /trade deny {$sender->getName()}.");
        $this->getScheduler()->scheduleDelayedTask(new class($this, $key) extends Task {
            private TradeUI $plugin;
            private string $key;
            public function __construct(TradeUI $plugin, string $key) { $this->plugin = $plugin; $this->key = $key; }
            public function onRun(): void {
                if (isset($this->plugin->pendingRequests[$this->key]) && time() >= $this->plugin->pendingRequests[$this->key]) {
                    unset($this->plugin->pendingRequests[$this->key]);
                }
            }
        }, 20 * 30);
    }

    private function handleAccept(Player $sender, Player $target): void {
        $key = strtolower($target->getName()) . '>' . strtolower($sender->getName());
        if (!isset($this->pendingRequests[$key])) {
            $sender->sendMessage("§cNo trade request from {$target->getName()}.");
            return;
        }
        unset($this->pendingRequests[$key]);
        $this->startSession($sender, $target);
    }

    private function handleDeny(Player $sender, Player $target): void {
        $key = strtolower($target->getName()) . '>' . strtolower($sender->getName());
        if (isset($this->pendingRequests[$key])) {
            unset($this->pendingRequests[$key]);
            $sender->sendMessage("§cTrade request from {$target->getName()} denied.");
            $target->sendMessage("§c{$sender->getName()} denied your trade request.");
        } else {
            $sender->sendMessage("§cNo trade request from {$target->getName()}.");
        }
    }

    private function startSession(Player $p1, Player $p2): void {
        $radius = (float)$this->config->get("trade-radius", 10);
        if ($p1->getPosition()->distance($p2->getPosition()) > $radius) {
            $p1->sendMessage("§cPlayer is too far. Get within $radius blocks.");
            return;
        }
        if (isset($this->sessions[$p1->getName()]) || isset($this->sessions[$p2->getName()])) {
            $p1->sendMessage("§cEither you or the target is already in a trade.");
            return;
        }
        $session = new TradeSession($this, $p1, $p2);
        $this->sessions[$p1->getName()] = $session;
        $this->sessions[$p2->getName()] = $session;
        $session->open();
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

    public function onPlayerQuit(PlayerQuitEvent $event): void {
        $player = $event->getPlayer();
        if (isset($this->sessions[$player->getName()])) {
            $this->sessions[$player->getName()]->cancel("player quit");
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
            if (!$this->p2->getInventory()->canAddItem($item)) {
                $this->cancel("inventory full");
                return;
            }
        }
        foreach ($this->offered2 as $item) {
            if (!$this->p1->getInventory()->canAddItem($item)) {
                $this->cancel("inventory full");
                return;
            }
        }
        foreach ($this->offered1 as $item) {
            $this->p2->getInventory()->addItem($item);
        }
        foreach ($this->offered2 as $item) {
            $this->p1->getInventory()->addItem($item);
        }
        $this->p1->sendMessage("§aTrade completed successfully.");
        $this->p2->sendMessage("§aTrade completed successfully.");
        $this->p1->removeCurrentWindow();
        $this->p2->removeCurrentWindow();
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
        $this->p1->removeCurrentWindow();
        $this->p2->removeCurrentWindow();
        $this->plugin->endSession($this);
    }
}
