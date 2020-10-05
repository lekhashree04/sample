<?php
/**
 * This file is used for updating Novalnet custom table
 * This free contribution made by request.
 * 
 * If you have found this script useful a small
 * recommendation as well as a comment on merchant form
 * would be greatly appreciated.
 *
 * @author       Novalnet AG
 * @copyright(C) Novalnet
 * All rights reserved. https://www.Novalnet.de/payment-plugins/kostenlos/lizenz
 */

namespace Novalnet\Migrations;

use Novalnet\Models\TransactionLog;
use Plenty\Modules\Plugin\DataBase\Contracts\Migrate;

/**
 * If need to update the Novalnet Transaction Table 
 */
class UpdateTransactionTableDataType
{
    
    public function run(Migrate $migrate)
    {
		$migrate->updateTable(TransactionLog::class);
    }
}
