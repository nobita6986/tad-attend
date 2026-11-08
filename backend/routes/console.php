<?php
use Illuminate\Support\Facades\Schedule;
Schedule::command('attendance:prune-images')->dailyAt('02:10')->timezone('Asia/Ho_Chi_Minh')->withoutOverlapping();
