<?php

namespace FixStudios\Blackjack;

use pocketmine\plugin\PluginBase;
use pocketmine\player\Player;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;

use cooldogedev\BedrockEconomy\api\BedrockEconomyAPI;
use cooldogedev\BedrockEconomy\libs\cooldogedev\libSQL\exception\SQLException;

use muqsit\invmenu\InvMenu;
use muqsit\invmenu\transaction\InvMenuTransaction;
use muqsit\invmenu\transaction\InvMenuTransactionResult;

use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\math\Vector3;

class Main extends PluginBase implements Listener {

    private array $blackjackGames = [];

    public function onEnable(): void {
        $this->getServer()->getLogger()->info("\n" .
            "___________.__  ____  ___\n" .
            "\_   _____/|__| \   \/  /\n" .
            " |    __)  |  |  \     / \n" .
            " |     \\   |  |  /     \\ \n" .
            " \___  /   |__| /___/\\  \\\n" .
            "     \\               \\_/");
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if (!$sender instanceof Player) {
            $sender->sendMessage("§cThis command can only be used in-game.");
            return true;
        }

        if (strtolower($command->getName()) === "bj") {
            if (!isset($args[0]) || !is_numeric($args[0]) || (int)$args[0] < 1) {
                $sender->sendMessage("§cUsage: /bj {amount} (amount must be a positive number)");
                return true;
            }

            $bet = (int)$args[0];
            $this->startBlackjack($sender, $bet);
            return true;
        }

        return false;
    }

    private function startBlackjack(Player $player, int $bet): void {
        $api = BedrockEconomyAPI::CLOSURE();

        $api->get(
            xuid: $player->getXuid(),
            username: $player->getName(),
            onSuccess: function(array $result) use ($player, $bet): void {
                $balance = $result["amount"] ?? 0;

                if ($balance < $bet) {
                    $player->sendMessage("§cInsufficient funds to bet $$bet.");
                    unset($this->blackjackGames[$player->getName()]);
                    return;
                }

                $playerCards = [rand(1, 11), rand(1, 11)];
                $dealerCards = [rand(1, 11), rand(1, 11)];

                $this->blackjackGames[$player->getName()] = [
                    "bet" => $bet,
                    "playerCards" => $playerCards,
                    "dealerCards" => $dealerCards,
                    "doubledDown" => false,
                    "ended" => false,
                    "lastWon" => null,
                ];

                if (array_sum($playerCards) === 21) {
                    $this->endBlackjack($player, true);
                    return;
                }

                $this->showBlackjackGame($player);
            },
            onError: function(SQLException $exception) use ($player): void {
                $player->sendMessage("§cError checking balance.");
                unset($this->blackjackGames[$player->getName()]);
            }
        );
    }

    private function showBlackjackGame(Player $player): void {
        $game = $this->blackjackGames[$player->getName()] ?? null;
        if ($game === null) return;

        $menu = InvMenu::create(InvMenu::TYPE_CHEST);
        $menu->setName("Blackjack - Bet: $" . $game["bet"]);
        $inventory = $menu->getInventory();
        $inventory->clearAll();

        if ($game["ended"] === true) {
            $restartItem = match ($game["lastWon"]) {
                true => VanillaItems::EMERALD()->setCustomName("§aRestart"),
                false => VanillaItems::REDSTONE_DUST()->setCustomName("§cRestart"),
                default => VanillaItems::GOLD_BLOCK()->setCustomName("§eDraw - Restart"),
            };

            $inventory->setItem(13, $restartItem);

            $menu->setListener(function(InvMenuTransaction $tx) use ($player): InvMenuTransactionResult {
                if (str_contains($tx->getItemClicked()->getCustomName(), "Restart")) {
                    $game = $this->blackjackGames[$player->getName()] ?? null;
                    if ($game === null) return $tx->discard();
                    $player->removeCurrentWindow();
                    $this->startBlackjack($player, $game["bet"]);
                }
                return $tx->discard();
            });

            $menu->send($player);
            return;
        }

        // Info on top row
        $playerCardsString = implode(", ", $game["playerCards"]);
        $dealerCardsString = implode(", ", $game["dealerCards"]);
        $playerTotal = array_sum($game["playerCards"]);
        $dealerTotal = array_sum($game["dealerCards"]);

        $inventory->setItem(1, VanillaItems::PAPER()->setCustomName("§bYour Cards: $playerCardsString = $playerTotal"));
        $inventory->setItem(7, VanillaItems::PAPER()->setCustomName("§cDealer Cards: $dealerCardsString = $dealerTotal"));

        // Middle row - action buttons
        $inventory->setItem(12, VanillaItems::EMERALD()->setCustomName("§aHit"));
        $inventory->setItem(13, VanillaItems::REDSTONE_DUST()->setCustomName("§cStand"));
        if (!$game["doubledDown"]) {
            $inventory->setItem(14, VanillaItems::GOLD_INGOT()->setCustomName("§6Double Down"));
        }

        $menu->setListener(function(InvMenuTransaction $tx) use ($player): InvMenuTransactionResult {
            $name = $tx->getItemClicked()->getCustomName();
            $game = $this->blackjackGames[$player->getName()] ?? null;
            if ($game === null) return $tx->discard();

            if ($name === "§aHit") {
                $this->playSound($player, "random.pop");
                $game["playerCards"][] = rand(1, 11);
                $this->blackjackGames[$player->getName()] = $game;

                $playerTotal = array_sum($game["playerCards"]);
                $player->removeCurrentWindow();

                if ($playerTotal > 21) {
                    $this->endBlackjack($player, false);
                } elseif ($playerTotal === 21) {
                    $this->endBlackjack($player, true);
                } else {
                    $this->showBlackjackGame($player);
                }
                return $tx->discard();
            }

            if ($name === "§cStand") {
                $this->playSound($player, "random.click");
                $player->removeCurrentWindow();
                $this->dealerPlay($player);
                return $tx->discard();
            }

            if ($name === "§6Double Down") {
                $this->playSound($player, "random.anvil_use");
                $game["bet"] *= 2;
                $game["playerCards"][] = rand(1, 11);
                $game["doubledDown"] = true;

                $this->blackjackGames[$player->getName()] = $game;
                $playerTotal = array_sum($game["playerCards"]);

                $player->removeCurrentWindow();

                if ($playerTotal > 21) {
                    $this->endBlackjack($player, false);
                } else {
                    $this->dealerPlay($player);
                }
                return $tx->discard();
            }

            return $tx->discard();
        });

        $menu->send($player);
    }

    private function dealerPlay(Player $player): void {
        $game = $this->blackjackGames[$player->getName()] ?? null;
        if ($game === null) return;

        while (array_sum($game["dealerCards"]) < 17) {
            $game["dealerCards"][] = rand(1, 11);
        }

        $this->blackjackGames[$player->getName()] = $game;

        $playerTotal = array_sum($game["playerCards"]);
        $dealerTotal = array_sum($game["dealerCards"]);

        // Bust if over 21
        if ($playerTotal > 21) {
            $this->endBlackjack($player, false);
            return;
        }

        // Draw if totals are equal and both are valid
        if ($playerTotal === $dealerTotal) {
            $this->endBlackjack($player, null);
            return;
        }

        // Win if player has higher total
        if ($playerTotal > $dealerTotal) {
            $this->endBlackjack($player, true);
        } else {
            $this->endBlackjack($player, false);
        }
    }

    private function endBlackjack(Player $player, bool|null $won): void {
        $game = $this->blackjackGames[$player->getName()] ?? null;
        if ($game === null) return;

        $bet = $game["bet"];
        $api = BedrockEconomyAPI::CLOSURE();

        $game["ended"] = true;
        $game["lastWon"] = $won;
        $this->blackjackGames[$player->getName()] = $game;

        if ($won === true) {
            $reward = $bet * 2;
            $api->add(
                xuid: $player->getXuid(),
                username: $player->getName(),
                amount: $reward,
                decimals: 0,
                onSuccess: function() use ($player, $reward): void {
                    $this->playSound($player, "random.levelup");
                    $player->sendMessage("§aYou won! You received $$reward.");
                    $this->getServer()->broadcastMessage("§a" . $player->getName() . " has won $$reward in blackjack!");
                    $this->showBlackjackGame($player);
                },
                onError: function(SQLException $exception) use ($player): void {
                    $player->sendMessage("§cError updating balance after win.");
                    $this->showBlackjackGame($player);
                }
            );
        } elseif ($won === false) {
            $player->sendMessage("§cYou lost the bet of $$bet.");
            $this->showBlackjackGame($player);
        } else {
            $player->sendMessage("§eDraw! You keep your bet.");
            $this->showBlackjackGame($player);
        }
    }

    private function playSound(Player $player, string $sound): void {
        $pk = new PlaySoundPacket();
        $pk->soundName = $sound;
        $pk->x = $player->getPosition()->getX();
        $pk->y = $player->getPosition()->getY();
        $pk->z = $player->getPosition()->getZ();
        $pk->volume = 1;
        $pk->pitch = 1;
        $player->getNetworkSession()->sendDataPacket($pk);
    }
}
