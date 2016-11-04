<?php
/**
 * Created on 2010-09-03
 *
 * @author "Sven Georgi" <gtasveni@web.de>
 * @version 0.1
 *
 *  class to parse MT940 - file
 *
 *
 * example:
 *
 * $mt940Reader = new class_File_ReadSta($mt940filename);
 *
 * foreach ($mt940Reader AS $row) {
 *   var_dump($row);
 * }
 *
 *
 */

class class_File_ReadSta implements Iterator
{
    protected $_lineEndings = "\r";
    protected $_content;
    protected $_count = 0;

    private $array;
    private $position = 0;

    /**
     *
     * @param string $fileName
     */
    public function __construct($fileName = '')
    {
        #$this->_lineEndings = ini_get('auto_detect_line_endings');
        #ini_set('auto_detect_line_endings', true);

        $this->position = 0;

        if (is_readable($fileName)) {
            $rcontent = array();
            $this->_getRawData(file_get_contents($fileName));

            while ($this->position < $this->_count) {
                $rcontent = $this->_parse($this->_content[$this->position]);

                foreach ($rcontent AS $row) {
                    $this->array[] = $row;
                }

                $this->position++;
            }
        }

        return false;
    }

    /** iterator methods **/
    public function rewind()
    {
        $this->position = 0;
    }

    public function current()
    {
        return $this->array[$this->position];
    }

    public function key()
    {
        return $this->position;
    }

    public function next()
    {
        ++$this->position;
    }

    public function valid()
    {
        return isset($this->array[$this->position]);
    }


    /** mt940-parser methods **/

    /**
     *
     * @param string $content
     */
    protected function _getRawData($content = '')
    {
        $rawContent     = preg_split("/[\s]+(-\r\n)/", $content);
        $this->_content = $rawContent;
        $this->_count   = count($rawContent);
    }

    /**
     *
     * @param string $line
     */
    protected function _parse($line = '')
    {
        foreach ($this->_parseLine($line) AS $row) {
            preg_match('/:(\d{2}(.?)):(.*)/', $row, $fields);
            $code = $fields[1];
            $v    = ($fields[3]);

            $val = array();

            if (strlen($code) > 0) {
                switch($code) {
                    case '20':
                    case '28C':
                    case '60F':
                        $val = $v;
                        break;

                    case '25':
                        $r = explode('/', $v);
                        if (strlen($r[0]) == 8) {
                            $val = array();
                            $val['ourbic'] = $r[0];
                            $val['ourban'] = preg_replace('/[^0-9]/', '', $r[1]);
                        }
                        break;

                    case '86':
                        $r = explode('?', $v);
                        $val = array();

                        $val['code'] = $r[0];
                        foreach ($r AS $rv) {
                            $fieldKey = intval(substr($rv,0,2));

                            switch($fieldKey) {
                                case 0:
                                    $val['type'] = substr($rv, 2);
                                    break;
                                case 10:
                                    $val['primanota'] = substr($rv, 2);
                                    break;
                                case 20:
                                case 21:
                                case 22:
                                case 23:
                                case 24:
                                case 25:
                                case 26:
                                case 27:
                                case 28:
                                case 29:
                                    $val['text'] .= substr($rv, 2);
                                    break;
                                case 30:
                                    $val['theirbic'] = substr($rv, 2);
                                    break;
                                case 31:
                                    $val['theirban'] = substr($rv, 2);
                                    break;
                                case 32:
                                case 33:
                                    $val['customer'] .= substr($rv, 2);
                                    break;
                                case 34:
                                    $val['keyextra'] = substr($rv,2);
                                case 60:
                                case 61:
                                case 62:
                                case 63:
                                    $val['textextra'] .= substr($rv, 2);
                                    break;
                            }
                        }
                        ksort($val);
                        break;

                    case '61':
                        $val = array();
                        preg_match('#(.*)//(.*)/(.*)#', $v, $q);
                        preg_match('/(\d{6})(\d{4}?)([RC|RD|C|D])(.*?)N(\w{3})(.*)/s', $q[1], $t);

                        $q = array_filter($q, 'trim');
                        $t = array_filter($t, 'trim');

                        $val['datevaluta'] = $t[1];

                        if (count($t == 7)) {
                            $val['datebook']    = $t[2];
                            $val['creditdebit'] = $t[3];
                            $val['turnover']    = floatval(str_replace(',', '.', $t[4]));
                            if (in_array($val['creditdebit'], array('D', 'RD'))) {
                                $val['turnover'] *= -1;
                            }

                            $val['bookingkey']  = 'N' . $t[5];
                            $val['reference']   = $t[6];
                        } else {
                            die('not used.');
                        }

                        $val['reserved1'] = $q[2];
                        $val['reserved2'] = $q[3];
                        break;

                    default:
                        $val = array();
                        break;
                }
                $return[$code][] = $val;
            }
        }

        $this->_parseField86($return);

        $realReturn =  array();

        if (count($return['61']) == count($return['86'])) {
            foreach ($return['61'] AS $key => $row) {

                foreach ($row AS $subKey => $subRow) {
                    $return['86'][$key][$subKey] = $subRow;
                }
                $return['86'][$key]['ourbic'] =$return['25'][0]['ourbic'];
                $return['86'][$key]['ourban'] =$return['25'][0]['ourban'];
            }
        } else {
            die('error while parsing .sta:  61 != 86');
        }

        return $return['86'];
    }

    /**
     *
     * @param unknown_type $rows
     */
    protected function _parseLine($rows = '')
    {
        $return = array();
        $rows   = explode("\r\n", $rows);
        $i      = 0;

        foreach ($rows AS $k => $row) {
            if (strpos($row, ':') !== false && strpos($row, ':') == 0) {
                $i++;
                $return[$i] = $row;
            } else {
                $return[$i] .= $row;
            }
        }

        return $return;
    }

    /**
     *
     * @param unknown_type $return
     */
    protected function _parseField86(&$return)
    {
        foreach ($return['86'] AS $key => $row) {

            // search for structured 86-field
            if (preg_match('#^(\d{1}/)#', $row['text'])) {
                $fields = preg_split('#/([A-Z]{3}):#', $row['text'], -1, PREG_SPLIT_DELIM_CAPTURE);

                $rreturn = array();
                $rreturn['gvfcode'] = $fields[0];
                unset($fields[0]);

                for ($i = 1; $i < count($fields); $i++) {
                    if ($i % 2 != 0) {
                        switch ($fields[$i]) {
                            case 'VWZ':
                                $return['86'][$key]['text'] = $fields[$i + 1];
                                break;
                            case 'FBB':
                                $return['86'][$key]['turnover'] = floatval(preg_replace('/[^0-9|.]/', '', str_replace(',', '.', $fields[$i + 1])));
                                break;
                            case 'FUR':
                                $return['86'][$key]['reference'] = $fields[$i + 1];
                            default:
                                // FUK, FGK, FBA, FCC ??
                                $rreturn[$fields[$i]] .= $fields[$i + 1];
                                break;
                        }
                    }
                }
                $return['86'][$key]['extra'] = $rreturn;
            }
        }
    }
}

?>