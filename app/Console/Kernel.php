<?php

use App\Http\Controllers\TransferInvoicesController;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;


class Kernel extends Command{

    protected function schedule(Schedule $schedule)
{
  $schedule->command('invoices:transfer')->everyTenMinutes();
}


}

