<?php
/**
 * LBS4 ILS Driver (LBS4)
 *
 * PHP version 7
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category VuFind
 * @package  ILS_Drivers
 * @author   Götz Hatop <vufind-tech@lists.sourceforge.net>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:plugins:ils_drivers Wiki
 */
namespace VuFind\ILS\Driver;

use VuFind\Exception\ILS as ILSException;
use VuFind\I18n\Translator\TranslatorAwareInterface;

/**
 * VuFind Connector for OCLC LBS4
 *
 * @category VuFind
 * @package  ILS_Drivers
 * @author   Götz Hatop <vufind-tech@lists.sourceforge.net>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:plugins:ils_drivers Wiki
 */
class LBS4 extends DAIA implements TranslatorAwareInterface
{
    use \VuFind\I18n\Translator\TranslatorAwareTrait;

    /**
     * Constructor
     *
     * @param \VuFind\Date\Converter $converter Date converter
     */
    public function __construct(\VuFind\Date\Converter $converter)
    {
        $this->dateConverter = $converter;
    }

    /**
     * Database connection
     *
     * @var resource
     */
    protected $db;

    /**
     * URL where epn can be appended to
     *
     * @var string
     */
    protected $opcloan;

    /**
     * ILN
     *
     * @var string
     */
    protected $opaciln;

    /**
     * FNO
     *
     * @var string
     */
    protected $opacfno;

    /**
     * Initialize the driver.
     *
     * Validate configuration and perform all resource-intensive tasks needed to
     * make the driver active.
     *
     * @throws ILSException
     * @return void
     */
    public function init()
    {
        parent::init(); // initialize DAIA parent

        if (isset($this->config['Catalog']['opaciln'])) {
            $this->opaciln = $this->config['Catalog']['opaciln'];
        }
        if (isset($this->config['Catalog']['opacfno'])) {
            $this->opacfno = $this->config['Catalog']['opacfno'];
        }
        if (isset($this->config['Catalog']['opcloan'])) {
            $this->opcloan = $this->config['Catalog']['opcloan'];
        }
        if (isset($this->config['Catalog']['database'])) {
            putenv("SYBASE=" . $this->config['Catalog']['sybpath']);
            $this->db = sybase_connect(
                $this->config['Catalog']['sybase'],
                $this->config['Catalog']['username'],
                $this->config['Catalog']['password']
            );
            sybase_select_db($this->config['Catalog']['database']);
        } else {
            throw new ILSException('No Database.');
        }
    }

    /**
     * Public Function which retrieves renew, hold and cancel settings from the
     * driver ini file.
     *
     * @param string $function The name of the feature to be checked
     * @param array  $params   Optional feature-specific parameters (array)
     *
     * @return array An array with key-value pairs.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getConfig($function, $params = null)
    {
        return $this->config[$function] ?? false;
    }

    /**
     * Patron Login
     *
     * This is responsible for authenticating a patron against the catalog.
     *
     * @param string $barcode The patron username
     * @param string $pin     The patron's password
     *
     * @throws ILSException
     * @return mixed Associative array of patron info on successful login,
     * null on unsuccessful login.
     */
    public function patronLogin($barcode, $pin)
    {
        $sql = "select b.borrower_bar "
             . ",b.first_name_initials_prefix"
             . ",b.name"
             . ",b.email_address"
             . ",b.registration_number"
             . ",b.borrower_type"
             . ",b.institution_code"
             . ",b.address_id_nr"
             . ",b.iln"
             . ",b.language_code"
             . " from borrower b, pincode p"
             . " where b.borrower_bar='" . $barcode . "'"
             . " and b.address_id_nr=p.address_id_nr"
             . " and b.iln=" . $this->opaciln
             . " and p.hashnumber = "
             . "     ascii(substring(convert(char(12),'" . $pin . "',104),1,1))"
             . " + 2*ascii(substring(convert(char(12),'" . $pin . "',104),2,1))"
             . " + 3*ascii(substring(convert(char(12),'" . $pin . "',104),3,1))"
             . " + 4*ascii(substring(convert(char(12),'" . $pin . "',104),4,1))"
             . " + 5*ascii(substring(convert(char(12),'" . $pin . "',104),5,1))"
             . " + 6*ascii(substring(convert(char(12),'" . $pin . "',104),6,1))"
             . " + 7*ascii(substring(convert(char(12),'" . $pin . "',104),7,1))"
             . " + 8*ascii(substring(convert(char(12),'" . $pin . "',104),8,1))";
        try {
            $result = [];
            $sqlStmt = sybase_query($sql);
            $row = sybase_fetch_row($sqlStmt);
            if ($row) {
                $result = ['id' => $barcode,
                              'firstname' => $row[1],
                              'lastname' => $row[2],
                              'cat_username' => $barcode,
                              'cat_password' => $pin,
                              'email' => $row[3],
                              'major' => $row[4],    // registration_number
                              'college' => $row[5],  // borrower_type
                              'address_id_nr' => $row[7],
                              'iln' => $row[8],
                              'lang' => $row[9]];
                return $result;
            } else {
                return null;
            }
        } catch (\Exception $e) {
            throw new ILSException($e->getMessage());
        }
    }

    /**
     * Get Patron Profile
     *
     * This is responsible for retrieving the profile for a specific patron.
     *
     * @param array $user The patron array
     *
     * @throws ILSException
     * @return array      Array of the patron's profile data on success.
     */
    public function getMyProfile($user)
    {
        $sql = "select b.borrower_bar "
             . ",b.first_name_initials_prefix"
             . ",b.name"
             . ",b.email_address"
             . ",b.free_text"
             . ",b.free_text_block" //5
             . ",b.borrower_type"
             . ",b.person_titles"
             . ",b.reminder_address"
             . ",a.sub_postal_code"
             . ",a.address_pob" //10
             . ",a.town"
             . ",a.telephone_number"
             . ",a.address_code"
             . " from borrower b, address a"
             . " where b.address_id_nr=a.address_id_nr"
             . "   and b.borrower_bar='" . $user['id'] . "'"
             . "   order by a.address_code asc";
        try {
            $result = [];
            $sqlStmt = sybase_query($sql);
            $row = sybase_fetch_row($sqlStmt);
            if ($row) {
                $result = [
                          'firstname' => $row[1],
                          'lastname'  => $row[2],
                          'address1'  => $row[10] . ', ' . $row[9] . ' ' . $row[11],
                          //'zip'     => $row[14],
                          'email'     => $row[3],
                          'phone'     => $row[12],
                          'group'     => $row[6],
                          ];
                if ($row[6] == '81') {
                    $result['group'] = $this->translate('Staff');
                } elseif ($row[6] == '1') {
                    $result['group'] = $this->translate('Student');
                } elseif ($row[6] == '30') {
                    $result['group'] = $this->translate('Residents');
                }
                $row = sybase_fetch_row($sqlStmt);
                if ($row) {
                    if ($row[8] == $row[13]) { //reminder address first
                        $result['address2'] = $result['address1'];
                        $result['address1']
                            = $row[10] . ', ' . $row[9] . ' ' . $row[11];
                    } else {
                        $result['address2']
                            = $row[10] . ', ' . $row[9] . ' ' . $row[11];
                    }
                }
                return $result;
            } else {
                return $user;
            }
        } catch (\Exception $e) {
            throw new ILSException($e->getMessage());
        }
    }

    /**
     * Get Patron Transactions
     *
     * This is responsible for retrieving all transactions (i.e. checked out items)
     * by a specific patron.
     *
     * @param array $patron The patron array from patronLogin
     *
     * @throws VuFind\Date\DateException
     * @throws ILSException
     * @return array        Array of the patron's transactions on success.
     */
    public function getMyTransactions($patron)
    {
        $aid = $patron['address_id_nr'];
        $iln = $patron['iln'];
        $lang = $patron['lang'];
        $sql = "exec loans_requests_rm_003 " . $aid . ", " . $iln . ", " . $lang;
        try {
            $result = [];
            $count = 0;
            $sqlStmt = sybase_query($sql);
            while ($row = sybase_fetch_row($sqlStmt)) {
                $result[$count] = [
                    'id' => $row[0],
                    'duedate' => substr($row[13], 0, 12),
                    'barcode' => $row[31],
                    'renew' => $row[7],
                    'publication_year' => $row[45],
                    'renewable' => $row[61],
                    'message' => $row[60],
                    'title' => $this->picaRecode($row[44]),
                    'item_id' => $row[7]
                ];
                $count++;
            }
            return $result;
        } catch (\Exception $e) {
            throw new ILSException($e->getMessage());
        }
        return [];
    }

    /**
     * Get Patron Holds
     *
     * This is responsible for retrieving all holds by a specific patron.
     *
     * @param array $patron The patron array from patronLogin
     *
     * @throws VuFind\Date\DateException
     * @throws ILSException
     * @return array Array of the patron's holds on success.
     */
    public function getMyHolds($patron)
    {
        $aid = $patron['address_id_nr'];
        $iln = $patron['iln'];
        //$lang = $patron['lang'];
        $sql = "select o.ppn"
            . ", o.shorttitle"
            . ", rtrim(convert(char(20),r.reservation_date_time,104))"
            . ", rtrim(convert(char(20),l.expiry_date_reminder,104))"
            . ", r.counter_nr_destination"
            . ", l.no_reminders"
            . ", l.period_of_loan"
            . " from reservation r, loans_requests l, ous_copy_cache o, volume v"
            . " where r.address_id_nr=" . $aid . ""
            . " and l.volume_number=r.volume_number"
            . " and v.volume_number=l.volume_number"
            . " and v.epn=o.epn"
            . " and l.iln=o.iln"
            . " and l.iln=" . $iln
            . "";
        try {
            $result = [];
            $sqlStmt = sybase_query($sql);
            while ($row = sybase_fetch_row($sqlStmt)) {
                $title = $this->picaRecode($row[1]);
                $result[] = [
                    'id'       => $this->prfz($row[0]),
                    'create'   => $row[2],
                    'expire'   => $row[3], // empty ?,
                    //'location' => $row[4],
                    'title'    => $title
                ];
            }
            return $result;
        } catch (\Exception $e) {
            throw new ILSException($e->getMessage());
        }
        return [];
    }

    /**
     * Get Purchase History
     *
     * This is responsible for retrieving the acquisitions history data for the
     * specific record (usually recently received issues of a serial).
     *
     * @param string $id The record id to retrieve the info for
     *
     * @return mixed     An array with the acquisitions data on success.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getPurchaseHistory($id)
    {
        return [];
    }

    /**
     * Get Patron Fines
     *
     * This is responsible for retrieving all fines by a specific patron.
     *
     * @param array $patron The patron array from patronLogin
     *
     * @throws VuFind\Date\DateException
     * @throws ILSException
     * @return mixed        Array of the patron's fines on success.
     */
    public function getMyFines($patron)
    {
        $aid = $patron['address_id_nr'];
        $iln = $patron['iln'];
        //$lang = $patron['lang'];
        $sql = "select o.ppn"
            . ", r.costs_code"
            . ", r.costs"
            . ", rtrim(convert(char(20),r.date_of_issue,104))"
            . ", rtrim(convert(char(20),r.date_of_creation,104))"
            . ", 'Overdue' as fines"
            . ", o.shorttitle"
            . " from requisition r, ous_copy_cache o, volume v"
            . " where r.address_id_nr=" . $aid . ""
            . " and r.iln=" . $iln
            . " and r.id_number=v.volume_number"
            . " and v.epn=o.epn"
            . " and r.iln=o.iln"
            . " and r.costs_code in (1, 2, 3, 4, 8)"
            . " union select id_number"
            . ", r.costs_code"
            . ", r.costs"
            . ", rtrim(convert(char(20),r.date_of_issue,104))"
            . ", rtrim(convert(char(20),r.date_of_creation,104))"
            . ", r.extra_information"
            . ", '' as zero"
            . " from requisition r"
            . " where r.address_id_nr=" . $aid . ""
            . " and r.costs_code not in (1, 2, 3, 4, 8)"
            . "";
        try {
            $result = [];
            $sqlStmt = sybase_query($sql);
            while ($row = sybase_fetch_row($sqlStmt)) {
                //$fine = $this->translate(('3'==$row[1])?'Overdue':'Dues');
                $fine = $this->picaRecode($row[5]);
                $amount = (null == $row[2]) ? 0 : $row[2] * 100;
                //$balance = (null==$row[3])?0:$row[3]*100;
                $checkout = substr($row[3], 0, 12);
                $duedate = substr($row[4], 0, 12);
                $title = $this->picaRecode(substr($row[6], 0, 12));
                $result[] = [
                    'id'      => $this->prfz($row[0]),
                    'amount'  => $amount,
                    'balance' => $amount, //wtf
                    'checkout' => $checkout,
                    'duedate' => $duedate,
                    'fine'    => $fine,
                    'title'   => $title,
                ];
            }
            return $result;
        } catch (\Exception $e) {
            throw new ILSException($e->getMessage());
        }
        return [];
    }

    /**
     * Helper function to clean up bad characters
     *
     * @param string $str input
     *
     * @return string
     */
    protected function picaRecode($str)
    {
        $clean = preg_replace('/[^(\x20-\x7F)]*/', '', $str);
        return $clean;
    }

    /**
     * Helper function to compute the modulo 11 based
     * ppn control number
     *
     * @param string $str input
     *
     * @return string
     */
    protected function prfz($str)
    {
        $x = 0;
        $y = 0;
        $w = 2;
        $stra = str_split($str);
        for ($i = strlen($str); $i > 0; $i--) {
            $c = $stra[$i - 1];
            $x = ord($c) - 48;
            $y += $x * $w;
            $w++;
        }
        $p = 11 - $y % 11;
        if ($p == 11) {
            $p = 0;
        }
        if ($p == 10) {
            $ret = $str . "X";
        } else {
            $ret = $str . $p;
        }
        return $ret;
    }
}
