<?php

declare(strict_types=1);

namespace SkulZOnTheYT\Jobs;

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
    private array $playerJobs = [];

    public function onEnable(): void {
        @mkdir($this->getDataFolder());
        $this->jobsConfig = new Config($this->getDataFolder() . "jobs.yml", Config::YAML, [
            "jobs" => [],
            "levels" => []
        ]);
        $this->playerJobs = $this->jobsConfig->getAll();

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getLogger()->info("Jobs Plugin Enabled!");
    }

    public function onDisable(): void {
        $this->jobsConfig->setAll($this->playerJobs);
        $this->jobsConfig->save();
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if ($command->getName() === "jobs") {
            if ($sender instanceof Player) {
                if (isset($args[0]) && in_array(strtolower($args[0]), ["leaderboard", "lead"])) {
                    $this->showLeaderboard($sender);
                } else {
                    $this->openJobForm($sender);
                }
            } else {
                $sender->sendMessage("§cThis command can only be used in-game.");
            }
            return true;
        }
        return false;
    }

    public function openJobForm(Player $player): void {
        $form = new MenuForm(
            "Choose Your Job",
            "Please select your job",
            [
                new Button("Miner", Image::path("textures/items/diamond_pickaxe.png")),
                new Button("Woodcutter", Image::path("textures/items/diamond_axe.png")),
                new Button("Fisher", Image::path("textures/items/fishing_rod_cast.png")),
                new Button("Farmer", Image::path("textures/items/diamond_hoe.png")),
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
                    $xuid = $player->getXuid();
                    $this->playerJobs["jobs"][$xuid] = $job;
                    $this->playerJobs["levels"][$xuid]["level"] = $this->playerJobs["levels"][$xuid]["level"] ?? 1;
                    $this->playerJobs["levels"][$xuid]["exp"] = $this->playerJobs["levels"][$xuid]["exp"] ?? 0;
                    $this->playerJobs["levels"][$xuid]["username"] = $player->getName();

                    $player->sendMessage("§aYou selected job §e" . ucfirst($job));
                }
            }
        );
        $player->sendForm($form);
    }

    public function onBlockBreak(BlockBreakEvent $event): void {
        $player = $event->getPlayer();
        $xuid = $player->getXuid();
        $job = $this->playerJobs["jobs"][$xuid] ?? null;
        if ($job === null) return;

        $block = $event->getBlock();
        $reward = 0;

        switch ($job) {
            case "miner":
                if (in_array($block->getTypeId(), [
                    BlockTypeIds::COAL_ORE, BlockTypeIds::IRON_ORE,
                    BlockTypeIds::GOLD_ORE, BlockTypeIds::DIAMOND_ORE,
                    BlockTypeIds::COPPER_ORE, BlockTypeIds::REDSTONE_ORE,
                    BlockTypeIds::EMERALD_ORE, BlockTypeIds::LAPIS_ORE,
                    BlockTypeIds::DEEPSLATE_COAL_ORE, BlockTypeIds::DEEPSLATE_IRON_ORE,
                    BlockTypeIds::DEEPSLATE_GOLD_ORE, BlockTypeIds::DEEPSLATE_DIAMOND_ORE,
                    BlockTypeIds::DEEPSLATE_COPPER_ORE, BlockTypeIds::DEEPSLATE_REDSTONE_ORE,
                    BlockTypeIds::DEEPSLATE_EMERALD_ORE, BlockTypeIds::DEEPSLATE_LAPIS_ORE,
                ], true)) {
                    $reward = 20;
                }
                break;

            case "woodcutter":
                if (in_array($block->getTypeId(), [
                    BlockTypeIds::OAK_LOG, BlockTypeIds::BIRCH_LOG,
                    BlockTypeIds::SPRUCE_LOG, BlockTypeIds::JUNGLE_LOG,
                    BlockTypeIds::ACACIA_LOG, BlockTypeIds::DARK_OAK_LOG,
                    BlockTypeIds::MANGROVE_LOG, BlockTypeIds::CHERRY_LOG,
                    BlockTypeIds::CRIMSON_STEM, BlockTypeIds::WARPED_STEM,
                ], true)) {
                    $reward = 10;
                }
                break;

            case "farmer":
                if (in_array($block->getTypeId(), [
                    BlockTypeIds::WHEAT, BlockTypeIds::CARROTS,
                    BlockTypeIds::POTATOES, BlockTypeIds::BEETROOTS,
                ], true)) {
                    $reward = 5;
                }
                break;
        }

        if ($reward > 0) {
            $this->addMoney($player, $reward);
        }
    }

    public function onTransaction(InventoryTransactionEvent $event): void {
        $player = $event->getTransaction()->getSource();
        $xuid = $player->getXuid();

        $job = $this->playerJobs["jobs"][$xuid] ?? null;
        if ($job !== "fisher") return;

        foreach ($event->getTransaction()->getActions() as $action) {
            $item = $action->getTargetItem();
            $fishIds = [
                VanillaItems::COD()->getTypeId(),
                VanillaItems::SALMON()->getTypeId(),
                VanillaItems::PUFFERFISH()->getTypeId(),
                VanillaItems::TROPICAL_FISH()->getTypeId(),
            ];
            if (in_array($item->getTypeId(), $fishIds, true)) {
                $this->addMoney($player, 15);
            }
        }
    }

    private function addMoney(Player $player, int $amount): void {
        BedrockEconomyAPI::CLOSURE()->add(
            xuid: $player->getXuid(),
            username: $player->getName(),
            amount: $amount,
            decimals: 0,
            onSuccess: static function() use ($player, $amount): void {
                $player->sendPopup("§a+§e$amount §amoney from your job");
            },
            onError: static function() use ($player): void {
                $player->sendPopup("§cFailed to add money to your account");
            },
        );
    }

    private function showLeaderboard(Player $player): void {
        $entries = [];
        foreach ($this->playerJobs["levels"] as $xuid => $data) {
            $entries[] = [
                "username" => $data["username"] ?? "Unknown",
                "job" => $this->playerJobs["jobs"][$xuid] ?? "None",
                "level" => $data["level"] ?? 1,
            ];
        }

        usort($entries, fn($a, $b) => $b["level"] <=> $a["level"]);
        $top = array_slice($entries, 0, 10);

        $msg = "§6=== Jobs Leaderboard ===\n";
        $rank = 1;
        foreach ($top as $entry) {
            $msg .= "§e$rank. §b{$entry["username"]} §7- §a{$entry["job"]} §f(Lv. {$entry["level"]})\n";
            $rank++;
        }
        $player->sendMessage($msg);
    }
}
