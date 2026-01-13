<?php
use Jankx\SimpleStats\StatsManager;

if (class_exists(StatsManager::class)) {
    StatsManager::getInstance()->init();
}
