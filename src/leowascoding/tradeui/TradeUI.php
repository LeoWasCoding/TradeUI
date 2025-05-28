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
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\item\StringToItemParser;
use pocketmine\scheduler\Task;
use pocketmine\scheduler\TaskHandler;
use jojoe77777\FormAPI\SimpleForm;
use jojoe77777\FormAPI\CustomForm;
use jojoe77777\FormAPI\ModalForm;

class TradeUI extends PluginBase implements Listener {
    private Config $config;
    private $messages;
    private array $pendingRequests = [];
    private array $sessions = [];

    public function onEnable(): void {
        if (!InvMenuHandler::isRegistered()) {
            InvMenuHandler::register($this);
        }
        // this is the config.yml
        @mkdir($this->getDataFolder());
        $this->saveDefaultConfig();
        $this->config = $this->getConfig();
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        // these are the messages.yml
        @mkdir($this->getDataFolder());
        $this->saveResource("messages.yml", false);
        $this->messages = new Config($this->getDataFolder() . "messages.yml", Config::YAML);
    }

    public function getPendingRequests(): array {
        return $this->pendingRequests;
    }
    
    public function hasPendingRequest(string $target, string $requester): bool {
        return isset($this->pendingRequests[$target]) && $this->pendingRequests[$target] === $requester;
    }
    
    public function removePendingRequest(string $target): void {
        unset($this->pendingRequests[$target]);
    }

    public function onDrop(PlayerDropItemEvent $event): void {
        $player = $event->getPlayer();
        if (isset($this->sessions[$player->getName()])) {
            $event->cancel();
        }
    }
    
    public function msg(string $key, array $vars = []): string {
        $message = $this->messages->get($key, "");
        foreach ($vars as $k => $v) {
            $message = str_replace("{" . $k . "}", (string)$v, $message);
        }
        return str_replace("&", "§", $message);
    }
    
    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if (!$sender instanceof Player) {
            return false;
        }
        if (strtolower($command->getName()) !== 'trade') {
            return false;
        }
    
        if (empty($args)) {
            $this->showMainMenu($sender);
            return true;
        }
    
        $action = strtolower($args[0]);
        if (in_array($action, ['accept', 'deny'], true)) {
            $this->handleResponse($sender, $action);

            if ($action === "deny") {
                if (isset($this->sessions[$sender->getName()])) {
                    $session = $this->sessions[$sender->getName()];
                    $session->cancelCountdown();
                }
            }
    
            return true;
        }

        $allowedWorlds = $this->getConfig()->get("allowed-worlds", []);
        $senderWorld = $sender->getWorld()->getFolderName();
    
        $target = $this->getServer()->getPlayerExact($args[0]) ?? $this->getServer()->getPlayerByPrefix($args[0]);
        if ($target instanceof Player) {
            $targetWorld = $target->getWorld()->getFolderName();
            if (!in_array($senderWorld, $allowedWorlds, true) || !in_array($targetWorld, $allowedWorlds, true)) {
                $sender->sendMessage("§cTrading is not allowed in this world.");
                return true;
            }
        }
    
        $this->handleRequest($sender, $args);
        return true;
    }

    private function showMainMenu(Player $player): void {
        $form = new SimpleForm(function(Player $player, ?int $data) {
            if ($data === null) return;
            if ($data === 0) {
                $this->showRequestForm($player);
            } elseif ($data === 1) {
                $this->showIncomingTradesForm($player);
            }
        });
        $form->setTitle("Trade Menu");
        $form->addButton("Request Trade");
        $form->addButton("Incoming Trades");
        $player->sendForm($form);
    }
    
    private function showRequestForm(Player $player): void {
        $radius = (float)$this->config->get('trade-radius', 10);
        $allowedWorlds = $this->config->get("allowed-worlds", []);
        $options = [];
    
        $playerWorld = $player->getWorld()->getFolderName();
        foreach (Server::getInstance()->getOnlinePlayers() as $p) {
            if ($p->getName() === $player->getName()) continue;
    
            $targetWorld = $p->getWorld()->getFolderName();
            if (!in_array($playerWorld, $allowedWorlds, true) || !in_array($targetWorld, $allowedWorlds, true)) {
                continue;
            }
    
            if ($player->getPosition()->distance($p->getPosition()) <= $radius) {
                $options[] = $p->getName();
            }
        }
    
        if (empty($options)) {
            $player->sendMessage($this->msg("noPlayersInRange", ["radius" => $radius]));
            return;
        }
    
        $form = new CustomForm(function(Player $player, $data) use ($options) {
            if ($data === null) return;
            $name = $options[(int)$data[0]] ?? null;
            if ($name === null) return;
            $this->handleRequest($player, [$name]);
        });
    
        $form->setTitle("Trade Request");
        $form->addDropdown("Select Player", $options);
        $player->sendForm($form);
    }
    
    private function showIncomingTradesForm(Player $player): void {
        $incoming = [];
        foreach ($this->pendingRequests as $target => $requester) {
            if ($target === $player->getName()) {
                $incoming[] = $requester;
            }
        }
    
        if (empty($incoming)) {
            $player->sendMessage($this->msg("noPendingRequests"));
            return;
        }
    
        $form = new SimpleForm(function(Player $player, ?int $data) use ($incoming) {
            if ($data === null) return;
            $requesterName = $incoming[$data] ?? null;
            if ($requesterName === null) return;
    
            $modal = new ModalForm(function(Player $player, ?bool $choice) use ($requesterName) {
                if ($choice === null) return;
    
                $action = $choice ? 'accept' : 'deny';
    
                if ($action === 'deny' && isset($this->sessions[$player->getName()])) {
                    $this->sessions[$player->getName()]->cancelCountdown();
                }
    
                $this->handleResponse($player, $action);
            });
    
            $modal->setTitle("Trade Request from $requesterName");
            $modal->setContent("Do you want to accept the trade request?");
            $modal->setButton1("Accept");
            $modal->setButton2("Deny");
            $player->sendForm($modal);
        });
    
        $form->setTitle("Incoming Trades");
        foreach ($incoming as $r) {
            $form->addButton("From $r");
        }
        $player->sendForm($form);
    }

    private function handleRequest(Player $sender, array $args): void {
        if (count($args) < 1) {
            $sender->sendMessage($this->msg("usage"));
            return;
        }
        $target = Server::getInstance()->getPlayerExact($args[0]);
        if (!$target instanceof Player || !$target->isOnline()) {
            $sender->sendMessage($this->msg("playerNotFound", ["target" => $args[0]]));
            return;
        }
        if ($target->getName() === $sender->getName()) {
            $sender->sendMessage($this->msg("cannotSelfTrade"));
            return;
        }
        if (isset($this->pendingRequests[$target->getName()]) || isset($this->pendingRequests[$sender->getName()])) {
            $sender->sendMessage($this->msg("alreadyPending"));
            return;
        }
        if (isset($this->sessions[$sender->getName()]) || isset($this->sessions[$target->getName()])) {
            $sender->sendMessage($this->msg("alreadyInSession"));
            return;
        }
        $radius = (float)$this->config->get('trade-radius', 10);
        if ($sender->getPosition()->distance($target->getPosition()) > $radius) {
            $sender->sendMessage($this->msg("tooFarRequest", ["radius" => $radius]));
            return;
        }

        $this->pendingRequests[$target->getName()] = $sender->getName();
        $sender->sendMessage($this->msg("requestSent", ["target" => $target->getName()]));
        $target->sendMessage($this->msg("requestReceived", ["requester" => $sender->getName()]));

        $timeoutTicks = (int)$this->config->get('request-timeout', 30) * 20;
        $this->getScheduler()->scheduleDelayedTask(new class($this, $target->getName(), $sender->getName()) extends Task {
            private TradeUI $plugin;
            private string $target;
            private string $requester;

            public function __construct(TradeUI $plugin, string $target, string $requester) {
                $this->plugin = $plugin;
                $this->target = $target;
                $this->requester = $requester;
            }

            public function onRun(): void {
                if ($this->plugin->hasPendingRequest($this->target, $this->requester)) {
                    $this->plugin->removePendingRequest($this->target);
                    $t = Server::getInstance()->getPlayerExact($this->target);
                    $r = Server::getInstance()->getPlayerExact($this->requester);
                    if ($t) $t->sendMessage($this->plugin->msg("requestExpiredTarget", ["requester" => $this->requester]));
                    if ($r) $r->sendMessage($this->plugin->msg("requestExpiredRequester", ["target" => $this->target]));
                }
            }
        }, $timeoutTicks);
    }

    private function handleResponse(Player $sender, string $action): void {
        $name = $sender->getName();
        if (!isset($this->pendingRequests[$name])) {
            $sender->sendMessage($this->msg("noPendingRequests"));
            return;
        }
        $requesterName = $this->pendingRequests[$name];
        unset($this->pendingRequests[$name]);
        $requester = Server::getInstance()->getPlayerExact($requesterName);
        if (!$requester || !$requester->isOnline()) {
            $sender->sendMessage($this->msg("requesterOffline", ["requester" => $requesterName]));
            return;
        }
        if ($action === 'deny') {
            $sender->sendMessage($this->msg("denyReceiver", ["requester" => $requesterName]));
            $requester->sendMessage($this->msg("denyRequester", ["target" => $name]));
            return;
        }

        // accept
        $radius = (float)$this->config->get('trade-radius', 10);
        if ($sender->getPosition()->distance($requester->getPosition()) > $radius) {
            $sender->sendMessage($this->msg("tooFarAccept"));
            $requester->sendMessage($this->msg("tooFarAccept"));
            return;
        }
        if (isset($this->sessions[$name]) || isset($this->sessions[$requesterName])) {
            $sender->sendMessage($this->msg("alreadyInSession"));
            return;
        }

        $session = new TradeSession($this, $requester, $sender);
        $this->sessions[$name] = $session;
        $this->sessions[$requesterName] = $session;
        $session->open();
    }

    public function endSession(TradeSession $session): void {
        unset($this->sessions[$session->getPlayer1()->getName()]);
        unset($this->sessions[$session->getPlayer2()->getName()]);
    }

    public function onInventoryClose(InventoryCloseEvent $event): void {
        $player = $event->getPlayer();
        if ($player instanceof Player && isset($this->sessions[$player->getName()])) {
            $this->sessions[$player->getName()]->cancel('inventory closed');
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
    private bool $finished = false;
    private bool $countdownActive = false;
    private ?TaskHandler $countdownHandler = null;

    private array $leftSlots = [0,1,2,3,9,10,11,12,18,19,20,21,27,28,29,30,36,37,38,39,45,46,47,48];
    private array $rightSlots = [5,6,7,8,14,15,16,17,23,24,25,26,32,33,34,35,41,42,43,44,49,50,51,52];
    private array $dividerSlots = [4,13,22,31,40];
    private int $confirmSlot = 53;

    private function msg(string $key, array $vars = []): string {
        return $this->plugin->msg($key, $vars);
    }

    public function __construct(TradeUI $plugin, Player $p1, Player $p2) {
        $this->plugin = $plugin;
        $this->p1 = $p1;
        $this->p2 = $p2;
        $this->menu1 = InvMenu::create(InvMenu::TYPE_DOUBLE_CHEST);
        $this->menu2 = InvMenu::create(InvMenu::TYPE_DOUBLE_CHEST);
        $this->menu1->setName("Trade with {$p2->getName()}");
        $this->menu2->setName("Trade with {$p1->getName()}");
        $self = $this;
        $this->menu1->setListener(function(InvMenuTransaction $tx) use ($self) {
            return $self->onInventoryClick($tx, $self->p1, $self->p2, $self->offered1, $self->confirmed1);
        });
        $this->menu2->setListener(function(InvMenuTransaction $tx) use ($self) {
            return $self->onInventoryClick($tx, $self->p2, $self->p1, $self->offered2, $self->confirmed2);
        });
    }

    public function open(): void {
        $player->removeCurrentWindow();
        $pane = StringToItemParser::getInstance()->parse('red_stained_glass_pane')->setCustomName('§c');
        foreach ($this->dividerSlots as $slot) {
            $this->menu1->getInventory()->setItem($slot, $pane);
            $this->menu2->getInventory()->setItem($slot, $pane);
        }
        $this->menu1->send($this->p1);
        $this->menu2->send($this->p2);
        $this->updateConfirmButtons();
        $this->p1->sendMessage($this->msg("tradeStarted", ["other" => $this->p2->getName()]));
        $this->p2->sendMessage($this->msg("tradeStarted", ["other" => $this->p1->getName()]));

        $timeout = (int)$this->plugin->getConfig()->get('trade-timeout', 30) * 20;
        $this->plugin->getScheduler()->scheduleDelayedTask(new class($this) extends Task {
            private TradeSession $session;
            public function __construct(TradeSession $session) { $this->session = $session; }
            public function onRun(): void { $this->session->cancel('timed out'); }
        }, $timeout);
    }

    private function updateConfirmButtons(): void {
        $c = StringToItemParser::getInstance()->parse('lime_dye')->setCustomName('§aConfirm trade');
        $u = StringToItemParser::getInstance()->parse('red_dye')->setCustomName('§cUnconfirm trade');
        $this->menu1->getInventory()->setItem($this->confirmSlot, $this->confirmed1 ? $u : $c);
        $this->menu2->getInventory()->setItem($this->confirmSlot, $this->confirmed2 ? $u : $c);
    }

    private function onInventoryClick(InvMenuTransaction $tx, Player $owner, Player $other, array &$offered, bool &$confirmed): InvMenuTransactionResult {
        $slot = $tx->getAction()->getSlot();
        if ($slot === $this->confirmSlot) {
            $confirmed = !$confirmed;
            if ($confirmed) {
                $owner->sendMessage($this->msg("confirmSuccess"));
                $other->sendMessage($this->msg("otherConfirmed", ["player" => $owner->getName()]));
                if ($this->confirmed1 && $this->confirmed2) {
                    $this->startCountdown();
                }
            } else {
                $owner->sendMessage($this->msg("unconfirm"));
                $other->sendMessage($this->msg("otherUnconfirm", ["player" => $owner->getName()]));
                $this->cancelCountdown();
            }
            $this->updateConfirmButtons();
            return $tx->discard();
        }

        if (in_array($slot, $this->dividerSlots, true) || in_array($slot, $this->rightSlots, true)) {
            return $tx->discard();
        }
        if (!in_array($slot, $this->leftSlots, true)) {
            return $tx->discard();
        }

        $item = clone $tx->getAction()->getTargetItem();
        if ($item->isNull()) {
            unset($offered[$slot]);
        } else {
            $offered[$slot] = $item;
        }
        $result = $tx->continue();

        // mirror change
        $index = array_search($slot, $this->leftSlots, true);
        $mirrorSlot = $this->rightSlots[$index];
        $mirrorInv = ($owner === $this->p1 ? $this->menu2->getInventory() : $this->menu1->getInventory());
        $mirrorInv->clear($mirrorSlot);
        if (!$item->isNull()) {
            $mirrorInv->setItem($mirrorSlot, $item);
        }

        // reset confirms and cancel countdown
        $this->confirmed1 = $this->confirmed2 = false;
        $this->updateConfirmButtons();
        $this->cancelCountdown();

        return $result;
    }

    private function startCountdown(): void {
        if ($this->finished || $this->countdownActive) {
            return;
        }
        $this->countdownActive = true;
        $this->p1->sendMessage($this->plugin->msg("countdownStart", ["seconds" => 3]));
        $this->p2->sendMessage($this->plugin->msg("countdownStart", ["seconds" => 3]));
    
        $this->countdownHandler = $this->plugin->getScheduler()->scheduleRepeatingTask(new class($this, $this->plugin) extends Task {
            private TradeSession $session;
            private TradeUI $plugin;
            private int $count = 3;
    
            public function __construct(TradeSession $session, TradeUI $plugin) {
                $this->session = $session;
                $this->plugin = $plugin;
            }
    
            public function onRun(): void {
                if ($this->count <= 0) {
                    $this->session->complete();
                    $this->getHandler()->cancel();
                    return;
                }
                $this->session->getPlayer1()->sendMessage($this->plugin->msg("countdownTick", ["seconds" => $this->count]));
                $this->session->getPlayer2()->sendMessage($this->plugin->msg("countdownTick", ["seconds" => $this->count]));
                $this->count--;
            }
        }, 20);
    }

    public function cancelCountdown(): void {
        if ($this->countdownActive && $this->countdownHandler !== null) {
            $this->countdownHandler->cancel();
            $this->countdownActive = false;
            $this->countdownHandler = null;
            $this->p1->sendMessage($this->msg("countdownCancel"));
            $this->p2->sendMessage($this->msg("countdownCancel"));
        }
    }

    public function complete(): void {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        foreach ($this->offered1 as $item) {
            if ($item->isNull()) continue;
            $left = $this->p2->getInventory()->addItem($item);
            foreach ($left as $drop) {
                $this->p2->getWorld()->dropItem($this->p2->getPosition(), $drop);
                $this->p2->sendMessage($this->msg("overflowDropped", ["item" => $drop->getName()]));
            }
        }
        foreach ($this->offered2 as $item) {
            if ($item->isNull()) continue;
            $left = $this->p1->getInventory()->addItem($item);
            foreach ($left as $drop) {
                $this->p1->getWorld()->dropItem($this->p1->getPosition(), $drop);
                $this->p1->sendMessage($this->msg("overflowDropped", ["item" => $drop->getName()]));
            }
        }
        $this->p1->sendMessage($this->msg("tradeComplete"));
        $this->p2->sendMessage($this->msg("tradeComplete"));
        $this->p1->removeCurrentWindow();
        $this->p2->removeCurrentWindow();
        $this->menu1->getInventory()->clearAll();
        $this->menu2->getInventory()->clearAll();
        $this->plugin->endSession($this);
    }

    public function cancel(string $reason): void {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        foreach ($this->offered1 as $item) {
            if (!$item->isNull()) {
                $this->p1->getInventory()->addItem($item);
            }
        }
        foreach ($this->offered2 as $item) {
            if (!$item->isNull()) {
                $this->p2->getInventory()->addItem($item);
            }
        }
        $this->p1->sendMessage($this->msg("cancelReturn", ["reason" => $reason]));
        $this->p2->sendMessage($this->msg("cancelReturn", ["reason" => $reason]));
        $this->p1->removeCurrentWindow();
        $this->p2->removeCurrentWindow();
        $this->menu1->getInventory()->clearAll();
        $this->menu2->getInventory()->clearAll();
        $this->plugin->endSession($this);
    }

    public function getPlayer1(): Player {
        return $this->p1;
    }

    public function getPlayer2(): Player {
        return $this->p2;
    }
}
