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
use Ifera\ScoreHud\event\TagsResolveEvent;
use Ifera\ScoreHud\event\PlayerTagUpdateEvent;
use Ifera\ScoreHud\scoreboard\ScoreTag;
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
    }

    public function onDisable(): void {
        $this->jobsConfig->setAll($this->playerJobs);
        $this->jobsConfig->save();
    }

    /**
     * Update ScoreHud tags ketika ScoreHud resolve tag
     */
    public function onTagResolve(TagsResolveEvent $event): void {
        $player = $event->getPlayer();
        $tag = $event->getTag();
        $xuid = $player->getXuid();

        $job = $this->playerJobs["jobs"][$xuid] ?? "None";
        $level = $this->playerJobs["levels"][$xuid]["level"] ?? 0;
        $exp = $this->playerJobs["levels"][$xuid]["exp"] ?? 0;

        switch($tag->getName()){
            case "jobs.name":
                $tag->setValue($job);
                break;

            case "jobs.level":
                $tag->setValue((string)$level);
                break;

            case "jobs.exp":
                $tag->setValue((string)$exp);
                break;
        }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if (strtolower($command->getName()) === "jobs") {
            if (!$sender instanceof Player) {
                $sender->sendMessage("§cCommand ini hanya bisa dipakai dalam game!");
                return true;
            }
    
           if(!isset($args[0])){
				$sender->sendMessage("§eUse §f/jobs help §efor a list of commands.");
				return true;
			}

			switch(strtolower($args[0])){
                case "join":
                    $this->openJobForm($sender);
                    break;
                case "help":
                    $sender->sendMessage("§6====[ Jobs Help ]====");
                    $sender->sendMessage("§e/jobs join §7- Buka menu untuk memilih job");
                    $sender->sendMessage("§e/jobs help §7- Lihat semua command Jobs");
                    $sender->sendMessage("§e/jobs leaderboard §7- Lihat leaderboard job");
                    $sender->sendMessage("§e/jobs info §7- Lihat job, level, dan exp kamu");
                    break;
    
                case "info":
                    $xuid = $sender->getXuid();
                    $job = $this->playerJobs["jobs"][$xuid] ?? "None";
                    $level = $this->playerJobs["levels"][$xuid]["level"] ?? 0;
                    $exp = $this->playerJobs["levels"][$xuid]["exp"] ?? 0;
    
                    $sender->sendMessage("§aJob: §f$job");
                    $sender->sendMessage("§aLevel: §f$level");
                    $sender->sendMessage("§aExp: §f$exp");
                    break;
    
                case "leaderboard":
                case "lead":
                    $this->showLeaderboard($sender);
                    break;
    
                default:
                    $sender->sendMessage("§cSubcommand tidak dikenal! Ketik §e/jobs help");
                    break;
            }
            return true;
        }
        return false;
    }

    public function openJobForm(Player $player): void {
        $xuid = $player->getXuid();
        $cooldown = (int) $this->getConfig()->get("jobSwitchCooldown", 3600);

        $lastSwitch = $this->playerJobs["levels"][$xuid]["lastSwitch"] ?? 0;
        $remaining = ($lastSwitch + $cooldown) - time();

        if ($remaining > 0 && isset($this->playerJobs["jobs"][$xuid])) {
            $minutes = ceil($remaining / 60);
            $player->sendMessage("§cKamu harus menunggu §e{$minutes} menit §csebelum bisa ganti job lagi!");
            return;
        }

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
                    $this->playerJobs["levels"][$xuid]["lastSwitch"] = time(); // simpan waktu terakhir ganti

                    (new PlayerTagUpdateEvent($player, new ScoreTag("jobs.name", $job)))->call();
                    (new PlayerTagUpdateEvent($player, new ScoreTag("jobs.level", (string)$this->playerJobs["levels"][$xuid]["level"])))->call();
                    (new PlayerTagUpdateEvent($player, new ScoreTag("jobs.exp", (string)$this->playerJobs["levels"][$xuid]["exp"])))->call();

                    $player->sendMessage("§aYou selected job §e" . ucfirst($job));
                }
            }
        );
        $player->sendForm($form);
    }

    private function giveJobReward(Player $player, string $xuid, string $job, int $rewardMoney, int $rewardExp, int $levelUpExp): void {
        $level = $this->playerJobs["levels"][$xuid]["level"] ?? 1;
        $exp = $this->playerJobs["levels"][$xuid]["exp"] ?? 0;

        // tambahin exp
        $exp += $rewardExp;

        // cek level up
        if ($exp >= $levelUpExp) {
            $level += intdiv($exp, $levelUpExp);
            $exp = $exp % $levelUpExp;
            $player->sendMessage("§6Level up! §fSekarang level §a$level");
        }

        // simpan kembali
        $this->playerJobs["levels"][$xuid]["level"] = $level;
        $this->playerJobs["levels"][$xuid]["exp"] = $exp;

        // kasih duit (money scale by level biar makin gede)
        $this->addMoney($player, $rewardMoney * $level);

        // update ScoreHud tags
        (new PlayerTagUpdateEvent($player, new ScoreTag("jobs.exp", (string)$exp)))->call();
        (new PlayerTagUpdateEvent($player, new ScoreTag("jobs.level", (string)$level)))->call();
        (new PlayerTagUpdateEvent($player, new ScoreTag("jobs.name", $job)))->call();
    }

    public function onBlockBreak(BlockBreakEvent $event): void {
        $player = $event->getPlayer();
        $xuid = $player->getXuid();
        $job = $this->playerJobs["jobs"][$xuid] ?? null;
        if ($job === null) return;

        $block = $event->getBlock();
        $rewardMoney = 0;
        $rewardExp = 0;

        // ambil reward dari config
        $jobReward = $this->getConfig()->getNested("rewards.$job", []);
        $baseMoney = $jobReward["money"] ?? 0;
        $baseExp = $jobReward["exp"] ?? 0;
        $levelUpExp = (int) $this->getConfig()->get("level_up_exp", 100);

        switch ($job) {
            case "miner":
                if (in_array($block->getTypeId(), [
                    BlockTypeIds::COAL_ORE, BlockTypeIds::IRON_ORE,
                    BlockTypeIds::GOLD_ORE, BlockTypeIds::DIAMOND_ORE,
                    BlockTypeIds::COPPER_ORE, BlockTypeIds::REDSTONE_ORE,
                    BlockTypeIds::EMERALD_ORE, BlockTypeIds::LAPIS_LAZULI_ORE,
                    BlockTypeIds::DEEPSLATE_COAL_ORE, BlockTypeIds::DEEPSLATE_IRON_ORE,
                    BlockTypeIds::DEEPSLATE_GOLD_ORE, BlockTypeIds::DEEPSLATE_DIAMOND_ORE,
                    BlockTypeIds::DEEPSLATE_COPPER_ORE, BlockTypeIds::DEEPSLATE_REDSTONE_ORE,
                    BlockTypeIds::DEEPSLATE_EMERALD_ORE, BlockTypeIds::DEEPSLATE_LAPIS_LAZULI_ORE,
                ], true)) {
                    $rewardMoney = $baseMoney;
                    $rewardExp = $baseExp;
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
                    $rewardMoney = $baseMoney;
                    $rewardExp = $baseExp;
                }
                break;

            case "farmer":
                if (in_array($block->getTypeId(), [
                    BlockTypeIds::WHEAT, BlockTypeIds::CARROTS,
                    BlockTypeIds::POTATOES, BlockTypeIds::BEETROOTS,
                ], true)) {
                    $rewardMoney = $baseMoney;
                    $rewardExp = $baseExp;
                }
                break;
        }

        if ($rewardMoney > 0 || $rewardExp > 0) {
            $this->giveJobReward($player, $xuid, $job, $rewardMoney, $rewardExp, $levelUpExp);
        }
    }

    public function onTransaction(InventoryTransactionEvent $event): void {
        $player = $event->getTransaction()->getSource();
        $xuid = $player->getXuid();

        $job = $this->playerJobs["jobs"][$xuid] ?? null;
        if ($job !== "fisher") return;

        $jobReward = $this->getConfig()->getNested("rewards.fisher", []);
        $baseMoney = $jobReward["money"] ?? 0;
        $baseExp = $jobReward["exp"] ?? 0;
        $levelUpExp = (int) $this->getConfig()->get("level_up_exp", 100);

        foreach ($event->getTransaction()->getActions() as $action) {
            $item = $action->getTargetItem();
            $fishIds = [
                VanillaItems::RAW_FISH()->getTypeId(),
                VanillaItems::RAW_SALMON()->getTypeId(),
                VanillaItems::PUFFERFISH()->getTypeId(),
            ];

            if (in_array($item->getTypeId(), $fishIds, true)) {
                $this->giveJobReward($player, $xuid, $job, $baseMoney, $baseExp, $levelUpExp);
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
