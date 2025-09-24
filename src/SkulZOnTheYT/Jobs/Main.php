<?php

declare(strict_types=1);

namespace JobsPlugin;

use forms\menu\Button;
use forms\menu\Image;
use forms\MenuForm;
use pocketmine\block\BlockTypeIds;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\event\Listener;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use cooldogedev\BedrockEconomy\api\BedrockEconomyAPI;
use pocketmine\item\VanillaItems;

class Main extends PluginBase implements Listener {

    private Config $jobsConfig;
    private Config $settingsConfig;
    private array $playerJobs = [];
    private array $playerLevels = [];
    private array $jobCooldowns = [];

    public function onEnable(): void {
        @mkdir($this->getDataFolder());

        $this->jobsConfig = new Config($this->getDataFolder() . "jobs.yml", Config::YAML, []);
        $this->playerJobs = $this->jobsConfig->get("jobs", []);
        $this->playerLevels = $this->jobsConfig->get("levels", []);

        $this->settingsConfig = new Config($this->getDataFolder() . "config.yml", Config::YAML);

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getLogger()->info("Jobs Plugin Enabled!");
    }

    public function onDisable(): void {
        $this->jobsConfig->set("jobs", $this->playerJobs);
        $this->jobsConfig->set("levels", $this->playerLevels);
        $this->jobsConfig->save();
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if ($command->getName() === "jobs") {
            if (!$sender instanceof Player) {
                $sender->sendMessage("§cThis command can only be used in-game.");
                return true;
            }

            if (empty($args)) {
                $this->openJobForm($sender);
                return true;
            }

            switch (strtolower($args[0])) {
                case "leaderboard":
                case "lead":
                    $this->showLeaderboard($sender);
                    return true;
            }
        }
        return false;
    }

    public function openJobForm(Player $player): void {
        $form = new MenuForm(
            "Choose a Job",
            "Select your profession",
            [
                new Button("⚒ Miner", Image::path("textures/items/diamond_pickaxe.png")),
                new Button("🪓 Woodcutter", Image::path("textures/items/diamond_axe.png")),
                new Button("🎣 Fisher", Image::path("textures/items/fishing_rod.png")),
                new Button("🌾 Farmer", Image::path("textures/items/diamond_hoe.png")),
            ],
            function(Player $player, Button $selected): void {
                $jobMap = [
                    0 => "miner",
                    1 => "woodcutter",
                    2 => "fisher",
                    3 => "farmer",
                ];
                $index = $selected->getValue();
                $job = $jobMap[$index] ?? null;

                if ($job !== null) {
                    $name = $player->getName();
                    $cooldown = $this->settingsConfig->get("jobSwitchCooldown", 3600);

                    if (isset($this->jobCooldowns[$name]) && time() - $this->jobCooldowns[$name] < $cooldown) {
                        $remaining = $cooldown - (time() - $this->jobCooldowns[$name]);
                        $player->sendMessage("§cYou must wait {$remaining}s before switching jobs again.");
                        return;
                    }

                    $this->playerJobs[$name] = $job;
                    $this->jobCooldowns[$name] = time();

                    if (!isset($this->playerLevels[$name])) {
                        $this->playerLevels[$name] = ["level" => 1, "exp" => 0];
                    }
                    $player->sendMessage("§aYou chose job §e" . ucfirst($job));
                }
            }
        );
        $player->sendForm($form);
    }

    public function onBlockBreak(BlockBreakEvent $event): void {
        $player = $event->getPlayer();
        $name = $player->getName();
        if (!isset($this->playerJobs[$name])) return;

        $job = $this->playerJobs[$name];
        $block = $event->getBlock();
        $reward = 0;
        $xp = 0;

        switch ($job) {
            case "miner":
                $minerBlocks = [
                    BlockTypeIds::COAL_ORE, BlockTypeIds::IRON_ORE, BlockTypeIds::GOLD_ORE,
                    BlockTypeIds::DIAMOND_ORE, BlockTypeIds::EMERALD_ORE, BlockTypeIds::REDSTONE_ORE,
                    BlockTypeIds::COPPER_ORE, BlockTypeIds::LAPIS_LAZULI_ORE, BlockTypeIds::DEEPSLATE_DIAMOND_ORE,
                ];
                if (in_array($block->getTypeId(), $minerBlocks, true)) {
                    $reward = $this->settingsConfig->get("rewards")["miner"];
                    $xp = $this->settingsConfig->get("xp")["miner"];
                }
                break;

            case "woodcutter":
                $woodBlocks = [
                    BlockTypeIds::OAK_LOG, BlockTypeIds::BIRCH_LOG, BlockTypeIds::SPRUCE_LOG,
                    BlockTypeIds::JUNGLE_LOG, BlockTypeIds::ACACIA_LOG, BlockTypeIds::DARK_OAK_LOG,
                    BlockTypeIds::MANGROVE_LOG, BlockTypeIds::CHERRY_LOG,
                ];
                if (in_array($block->getTypeId(), $woodBlocks, true)) {
                    $reward = $this->settingsConfig->get("rewards")["woodcutter"];
                    $xp = $this->settingsConfig->get("xp")["woodcutter"];
                }
                break;

            case "farmer":
                $farmerBlocks = [
                    BlockTypeIds::WHEAT, BlockTypeIds::CARROTS, BlockTypeIds::POTATOES,
                    BlockTypeIds::BEETROOTS, BlockTypeIds::MELON, BlockTypeIds::PUMPKIN,
                ];
                if (in_array($block->getTypeId(), $farmerBlocks, true)) {
                    $reward = $this->settingsConfig->get("rewards")["farmer"];
                    $xp = $this->settingsConfig->get("xp")["farmer"];
                }
                break;
        }

        if ($reward > 0) {
            $this->rewardPlayer($player, $reward, $xp);
        }
    }

    public function onTransaction(InventoryTransactionEvent $event): void {
        $player = $event->getTransaction()->getSource();
        $name = $player->getName();

        if (!isset($this->playerJobs[$name]) || $this->playerJobs[$name] !== "fisher") {
            return;
        }

        foreach ($event->getTransaction()->getActions() as $action) {
            $item = $action->getTargetItem();
            $fishIds = [
                VanillaItems::COD()->getTypeId(),
                VanillaItems::SALMON()->getTypeId(),
                VanillaItems::PUFFERFISH()->getTypeId(),
                VanillaItems::TROPICAL_FISH()->getTypeId(),
            ];
            if (in_array($item->getTypeId(), $fishIds, true)) {
                $reward = $this->settingsConfig->get("rewards")["fisher"];
                $xp = $this->settingsConfig->get("xp")["fisher"];
                $this->rewardPlayer($player, $reward, $xp);
            }
        }
    }

    private function rewardPlayer(Player $player, int $reward, int $xp): void {
        BedrockEconomyAPI::CLOSURE()->add(
            xuid: $player->getXuid(),
            username: $player->getName(),
            amount: $reward,
            decimals: 0,
            onSuccess: function() use ($player, $reward, $xp): void {
                $player->sendPopup("§a+§e$reward §amoney from your job");
                $this->addExp($player, $xp);
            },
            onError: static function() use ($player): void {
                $player->sendPopup("§cFailed to add money to " . $player->getName());
            },
        );
    }

    private function addExp(Player $player, int $exp): void {
        $name = $player->getName();

        if (!isset($this->playerLevels[$name])) {
            $this->playerLevels[$name] = ["level" => 1, "exp" => 0];
        }

        $this->playerLevels[$name]["exp"] += $exp;

        $baseXp = $this->settingsConfig->get("level")["xp_required"];
        $scaling = $this->settingsConfig->get("level")["scaling"];
        $currentLevel = $this->playerLevels[$name]["level"];
        $neededXp = (int)($baseXp * ($scaling ** ($currentLevel - 1)));

        if ($this->playerLevels[$name]["exp"] >= $neededXp) {
            $this->playerLevels[$name]["exp"] = 0;
            $this->playerLevels[$name]["level"]++;
            $player->sendMessage("§bYou leveled up! Now level " . $this->playerLevels[$name]["level"]);
        }
    }

    private function showLeaderboard(Player $player): void {
        if (empty($this->playerLevels)) {
            $player->sendMessage("§cNo players in leaderboard yet.");
            return;
        }

        uasort($this->playerLevels, fn($a, $b) => $b["level"] <=> $a["level"]);

        $limit = $this->settingsConfig->get("leaderboard")["top_limit"] ?? 10;
        $message = "§6=== Jobs Leaderboard ===\n";
        $rank = 1;
        foreach ($this->playerLevels as $name => $data) {
            $job = $this->playerJobs[$name] ?? "None";
            $message .= "§e#$rank §f$name §7- Job: §a" . ucfirst($job) . " §7| Level: §b" . $data["level"] . "\n";
            if ($rank++ >= $limit) break;
        }

        $player->sendMessage($message);
    }
}