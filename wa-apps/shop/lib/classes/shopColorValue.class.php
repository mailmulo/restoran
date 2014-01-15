<?php

/**
 * Class shopColorValue
 * @property-read int[] $rgb RGB color code
 * @property-read int[] $hsv HSV color code
 * @property-read int[] $cmyk CMYK color code
 * @property-read string $hex HEX color code
 * @property-read string $html Color icon and name
 * @property-read string $icon HTML colorized icon
 * @property-read string $style CSS style for element
 * @property string $value Color name
 * @property int $code
 */
class shopColorValue implements ArrayAccess
{
    const RGB = 'rgb';
    const HEX = 'hex';
    const CMYK = 'cmyk';
    const HSV = 'hsv';

    private $code;
    private $value;
    private $_data;

    public function __construct($row)
    {
        foreach ($row as $field => $value) {
            $this->{$field} = $value;
        }
    }

    public function __set($field, $value)
    {
        return $this->$field = $value;
    }

    public function __get($field)
    {
        switch ($field) {
            case self::HSV:
            case self::RGB:
            case self::CMYK:
                if (!isset($this->_data[$field])) {
                    $this->_data[$field] = $this->convert($field, $this->code);
                }
                return $this->_data[$field];
            case 'style':
                $style = "";
                if ($this->code !== null) {
                    $style .= "color:{$this->convert(self::HEX, 0xFFFFFF & ~$this->code)};";
                    $style .= "background-color:{$this->hex};";
                }

                return $style;
                break;
            case 'html':
                $name = htmlentities(ifempty($this->value, $this->hex), ENT_QUOTES, 'utf-8');
                return <<<HTML
<span style="white-space: nowrap;">{$this->icon}{$name}</span>
HTML;

                break;
            case 'icon':
                if ($this->code === null) {
                    return null;
                } else {
                    return <<<HTML
 <i class="icon16 color" style="background:{$this->hex};"></i>
HTML;
                }
                break;
            default:
                return isset($this->$field) ? $this->$field : $this->convert($field);
                break;
        }
    }

    public function getRaw()
    {
        return array(
            'id'    => $this->id,
            'code'  => $this->code,
            'value' => $this->value,
            'sort'  => $this->sort,
        );
    }

    private static function getColors($locale = null)
    {
        static $color_spaces = array();
        if ($locale === null) {
            $locale = wa()->getLocale();
        }
        if (!isset($color_spaces[$locale])) {
            $path = realpath(dirname(__FILE__)).'/../config/data/color.'.$locale.'.php';
            if (file_exists($path)) {
                $color_spaces[$locale] = include($path);
            } elseif ($locale != 'en_US') {
                $color_spaces[$locale] = self::getColors('en_US');
            }
            if (!is_array($color_spaces[$locale])) {
                $color_spaces[$locale] = array();
            }
        }
        return ifset($color_spaces[$locale], reset($color_spaces));
    }

    public static function getName($code)
    {
        $name = '#'.$code;
        $base = array(
            0xFF & ($code >> 16),
            0xFF & ($code >> 8),
            0xFF & $code,
        );
        $d = 128;
        foreach (self::getColors() as $code => $code_name) {
            $code = array(
                0xFF & ($code >> 16),
                0xFF & ($code >> 8),
                0xFF & $code,
            );
            $d_ = sqrt(pow($base[0] - $code[0], 2) + pow($base[1] - $code[1], 2) + pow($base[2] - $code[2], 2));
            if ($d_ < $d) {
                $d = $d_;
                if (is_array($code_name)) {
                    $name = reset($code_name);
                } else {
                    $name = $code_name;
                }
            }
        }
        return $name;

    }

    public static function getCode($name)
    {
        $like = 0;
        $code = null;
        foreach (self::getColors() as $code_ => $code_name) {
            foreach ((array)$code_name as $name_) {
                similar_text($name, $name_, $percent);
                if (($percent > 60) && ($percent > $like)) {
                    $like = $percent;
                    $code = $code_;
                }
            }
        }
        return $code;
    }


    public function __toString()
    {
        return (wa()->getEnv() == 'frontend') ? $this->html : $this->value;
    }

    public function convert($format, $value = null, $raw = false)
    {
        if ($value === null) {
            $value = $this->code;
        }
        $pattern = null;
        if ($value !== null) {

            switch ($format) {
                case self::RGB:
                    $value = array(
                        0xFF & ($value >> 16),
                        0xFF & ($value >> 8),
                        0xFF & $value,
                    );
                    $pattern = 'rgb(%d,%d,%d)';
                    break;

                case self::HEX:
                    $value = sprintf('#%06X', $value);
                    break;

                case self::CMYK:
                    $r = 0xFF & ($value >> 16);
                    $g = 0xFF & ($value >> 8);
                    $b = 0xFF & $value;
                    $c_ = 1.0 - ($r / 255);
                    $m_ = 1.0 - ($g / 255);
                    $y_ = 1.0 - ($b / 255);

                    $black = min($c_, $m_, $y_);
                    $cyan = ($c_ - $black) / (1.0 - $black);
                    $magenta = ($m_ - $black) / (1.0 - $black);
                    $yellow = ($y_ - $black) / (1.0 - $black);
                    $pattern = 'cmyk(%0.2f,%0.2f,%0.2f,%0.2f)';
                    $value = array($cyan, $magenta, $yellow, $black);
                    break;

                case self::HSV:
                    $r = 0xFF & ($value >> 16);
                    $g = 0xFF & ($value >> 8);
                    $b = 0xFF & $value;
                    $rgb_max = max($r, $g, $b);

                    $hsv = array(
                        'hue' => 0,
                        'sat' => 0,
                        'val' => $rgb_max,
                    );

                    if ($hsv['val'] > 0) {
                        /* Normalize value to 1 */
                        $r /= $hsv['val'];
                        $g /= $hsv['val'];
                        $b /= $hsv['val'];

                        $rgb_min = min($r, $g, $b);
                        $rgb_max = max($r, $g, $b);


                        $hsv['sat'] = $rgb_max - $rgb_min;
                        if ($hsv['sat'] > 0) {
                            /* Normalize saturation to 1 */
                            $r = ($r - $rgb_min) / ($rgb_max - $rgb_min);
                            $g = ($g - $rgb_min) / ($rgb_max - $rgb_min);
                            $b = ($b - $rgb_min) / ($rgb_max - $rgb_min);
                            $rgb_max = max($r, $g, $b);

                            /* Compute hue */
                            if ($rgb_max == $r) {
                                $hsv['hue'] = 0.0 + 60.0 * ($g - $b);
                                if ($hsv['hue'] < 0.0) {
                                    $hsv['hue'] += 360.0;
                                }
                            } else if ($rgb_max == $g) {
                                $hsv['hue'] = 120.0 + 60.0 * ($b - $r);
                            } else /* rgb_max == $b */ {
                                $hsv['hue'] = 240.0 + 60.0 * ($r - $g);
                            }
                        }
                    }
                    $pattern = 'hsv(%d,%d,%d)';
                    $value = array_values($hsv);
                    break;

                default:
                    break;
            }

            if (empty($raw) && !empty($pattern)) {
                $args = array_merge(array($pattern), (array)$value);
                $value = call_user_func_array('sprintf', $args);
            }
        }
        return $value;
    }


    public function offsetGet($offset)
    {
        return $this->__get($offset);
    }

    public function offsetSet($offset, $value)
    {
        return $this->__set($offset, $value);
    }

    public function offsetUnset($offset)
    {

    }

    public function offsetExists($offset)
    {
        return true;
    }

}