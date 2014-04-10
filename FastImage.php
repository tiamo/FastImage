<?php

class FastImage
{
    const COLOR_TYPE_UNKNOWN = -1;
    const COLOR_TYPE_GRAYSCALE = 0;
    const COLOR_TYPE_GRAYSCALE_INDEXED = 1;
    const COLOR_TYPE_TRUECOLOR = 2;
    const COLOR_TYPE_INDEXED = 3;
    const COLOR_TYPE_GRAYSCALE_ALPHA = 4;
    const COLOR_TYPE_GRAYSCALE_ALPHA_INDEXED = 5;
    const COLOR_TYPE_TRUECOLOR_ALPHA = 6;
    const COLOR_TYPE_INDEXED_ALPHA = 7;
	
    const FORMAT_UNKNOWN = '?';
    const FORMAT_JPEG = 'jpg';
    const FORMAT_PNG = 'png';
    const FORMAT_GIF = 'gif';
    const FORMAT_BMP = 'bmp';
	
	public $uri;
	public $name;
	public $type;
	public $error;
	
	public $format = self::FORMAT_UNKNOWN;
	public $size = 0;
	public $progressive = false;
	public $width = -1;
	public $height = -1;
	public $physicalWidthDpi = -1;
	public $physicalHeightDpi = -1;
	public $bitsPerPixel = -1;
	public $colorType = self::COLOR_TYPE_UNKNOWN;
	public $comments = array();
	
	private $_handle;
	
	public function __construct($uri)
	{
		$inf = parse_url($uri);
		if (empty($inf['scheme']) && empty($inf['host']))
			return $this->__error('Bad image uri, empty scheme or host');
		
		// get_headers not work with https
		$uri = str_replace('https://', 'http://', $uri);
		$headers = @get_headers($uri, true);
		
		if (empty($headers['Content-Length']))
			return $this->__error('Bad image size');
		
		$this->uri = is_array($headers['Location']) ? $headers['Location'][0] : $headers['Location'];
		$this->name = pathinfo($this->uri, PATHINFO_FILENAME);
		$this->size = is_array($headers['Content-Length']) ? end($headers['Content-Length']) : $headers['Content-Length'];
		
		// open file handle
		if ($this->uri)
			$this->_handle = @fopen($this->uri, 'rb');
		
		if ($this->_handle)
			$this->check();
		
		// mime type
		if (!$this->type && $this->format)
			$this->type = 'image/' . $this->format;
	}
	
	public function __destruct()
	{
		if ($this->_handle)
		{
			fclose($this->_handle);
		}
	}
	
	public function __error($error)
	{
		$this->error = $error;
	}
	
    /**
     * Call this method after you have provided an input stream or file
     * If true is returned, the file format was known and information
     * on the file's content can be retrieved using the various getXyz methods.
     *
     * @return if information could be retrieved from input
     */
	public function check()
	{
		$this->format = -1;
		$this->width = -1;
		$this->height = -1;
		$this->bitsPerPixel = -1;
		$this->numberOfImages = 1;
		$this->physicalWidthDpi = -1;
		$this->physicalHeightDpi = -1;
		
		$b1 = $this->readByte() & 0xff;
		$b2 = $this->readByte() & 0xff;
		
		if ($b1 == 0x47 && $b2 == 0x49) {
			return $this->checkGif();
		} else if ($b1 == 0x89 && $b2 == 0x50) {
			return $this->checkPng();
		} else if ($b1 == 0xff && $b2 == 0xd8) {
			$this->type = 'image/jpeg';
			return $this->checkJpeg();
		} else if ($b1 == 0x42 && $b2 == 0x4d) {
			return $this->checkBmp();
		// } else if ($b1 == 0x0a && $b2 < 0x06) {
			// return $this->checkPcx();
		// } else if ($b1 == 0x46 && $b2 == 0x4f) {
			// return $this->checkIff();
		// } else if ($b1 == 0x59 && $b2 == 0xa6) {
			// return $this->checkRas();
		// } else if ($b1 == 0x50 && $b2 >= 0x31 && $b2 <= 0x36) {
			// return $this->checkPnm($b2 - '0');
		} else if ($b1 == 0x38 && $b2 == 0x42) {
			return $this->checkPsd();
		// } else if ($b1 == 0x46 && $b2 == 0x57) {
			// return $this->checkSwf();
		}
		
		return false;
	}
	
	private function checkBmp()
	{
		$bytes = $this->read(44);
		
		if (sizeof($bytes) != 44)
            return false;
		
		// get width and height
		$this->width = $this->getIntLittleEndian($bytes, 16);
		$this->height = $this->getIntLittleEndian($bytes, 20);
		
        if ($this->width < 1 || $this->height < 1)
            return false;
		
		// get bitsPerPixel
		$this->bitsPerPixel = $this->getShortLittleEndian($bytes, 26);
		
        if (!in_array($this->bitsPerPixel, array(1,4,8,16,24,32)))
			return false;
		
		$this->format = self::FORMAT_BMP;
		return true;
    }
	
	private function checkGif()
	{
        $GIF_MAGIC_87A = array(0x46, 0x38, 0x37, 0x61);
        $GIF_MAGIC_89A = array(0x46, 0x38, 0x39, 0x61);
		
		$bytes = $this->read(11); // 4 from the GIF signature + 7 from the global header
        
		if (sizeof($bytes) < 11)
            return false;
		
		// check gif signature
        if ((!$this->equals($bytes, 0, $GIF_MAGIC_89A, 0, 4)) && (!$this->equals($bytes, 0, $GIF_MAGIC_87A, 0, 4)))
            return false;
		
        $this->format = self::FORMAT_GIF;
		$this->width = $this->getShortLittleEndian($bytes, 4);
        $this->height = $this->getShortLittleEndian($bytes, 6);
		
        $flags = $bytes[8] & 0xff;
        $this->bitsPerPixel = (($flags >> 4) & 0x07) + 1;
        $this->progressive = ($flags & 0x02) != 0;
		
        // skip global color palette
        if (($flags & 0x80) != 0) {
            $tableSize = (1 << (($flags & 7) + 1)) * 3;
			$this->skipBytes($tableSize);
        }
		
        $this->numberOfImages = 0;
        
        do
		{
            $blockType = $this->readByte();
			
            switch ($blockType)
			{
                case (0x2c): // image separator
					$flags = $bytes[8] & 0xff;
					$localBitsPerPixel = ($flags & 0x07) + 1;
					if ($localBitsPerPixel > $this->bitsPerPixel) {
						$this->bitsPerPixel = $localBitsPerPixel;
					}
					if (($flags & 0x80) != 0) {
						$this->skipBytes((1 << $localBitsPerPixel) * 3);
					}
					$this->skipBytes(1); // initial code length
					do {
						$n = $this->readByte();
						if ($n > 0) {
							$this->skipBytes($n);
						} else if ($n == -1) {
							return false;
						}
					} while ($n > 0);
					$this->numberOfImages++;
					break;
                case (0x21):
					do {
						$n = $this->readByte();
						if ($n > 0) {
							$this->skipBytes($n);
						} else if ($n == -1) {
							return false;
						}
					} while ($n > 0);
					break;
                case (0x3b): // end of file
					break;
                default:
					return false;
            }
        } while ($blockType != 0x3b);
		
        return true;
    }
	
    private function checkJpeg()
	{
        while (true)
		{
			$bytes = $this->read(4);
			
			if (sizeof($bytes) != 4)
				return false;
			
            $marker = $this->getShortBigEndian($bytes, 0);
            $size = $this->getShortBigEndian($bytes, 2);
			
            if (($marker & 0xff00) != 0xff00)
			{
				return false; // not a valid marker
            }
			
            if ($marker == 0xffe0) // APPx 
			{
                if ($size < 14)
                    return false; // APPx header must be >= 14 bytes
				
				$bytes = $this->read(12);
				if (sizeof($bytes) != 12)
					return false;
				
                $APP0_ID = array(0x4a, 0x46, 0x49, 0x46, 0x00);
				
                if ($this->equals($APP0_ID, 0, $bytes, 0, 5))
				{
                    if ($bytes[7] == 1)
					{
						$this->setPhysicalWidthDpi($this->getShortBigEndian($bytes, 8));
                        $this->setPhysicalHeightDpi($this->getShortBigEndian($bytes, 10));
                    }
					else if ($bytes[7] == 2)
					{
                        $x = $this->getShortBigEndian($bytes, 8);
                        $y = $this->getShortBigEndian($bytes, 10);
                        $this->setPhysicalWidthDpi((int) ($x * 2.54));
                        $this->setPhysicalHeightDpi((int) ($y * 2.54));
                    }
                }
				$this->skipBytes($size - 14);
            }
			else if (($marker == 0xffe1 || $marker == 0xfffe) && $size > 2)  // Comment
			{
				// TODO:
				
				$size -= 2;
				$chars = $this->read($size);
                if (sizeof($chars) != $size) {
					return false;
                }
				$this->addComment($this->showBytes($chars));
				
			}
			else if ($marker >= 0xffc0 && $marker <= 0xffcf && $marker != 0xffc4 && $marker != 0xffc8)
			{
				$bytes = $this->read(6);
				
                $this->format = self::FORMAT_JPEG;
                $this->bitsPerPixel = ($bytes[0] & 0xff) * ($bytes[5] & 0xff);
                $this->progressive = $marker == 0xffc2 || $marker == 0xffc6 || $marker == 0xffca || $marker == 0xffce;
                $this->width = $this->getShortBigEndian($bytes, 3);
                $this->height = $this->getShortBigEndian($bytes, 1);
				
                return true;
            }
			else
			{
                $this->skipBytes($size - 2);
            }
        }
    }
	
    private function checkPng()
	{
        $PNG_MAGIC = array(0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a);
		$bytes = $this->read(27);
		
		if (sizeof($bytes) != 27)
            return false;
		
        if (!$this->equals($bytes, 0, $PNG_MAGIC, 0, 6))
            return false;
		
		$this->format = self::FORMAT_PNG;
		$this->width = $this->getIntBigEndian($bytes, 14);
		$this->height = $this->getIntBigEndian($bytes, 18);
		$this->bitsPerPixel = $bytes[22] & 0xff;
		
		$this->colorType = $bytes[23] & 0xff;
		
		switch($this->colorType) {
		case(self::COLOR_TYPE_GRAYSCALE_ALPHA):
			$this->bitsPerPixel *= 2;
			break;
		case(self::COLOR_TYPE_TRUECOLOR):
			$this->bitsPerPixel *= 3;
			break;
		case(self::COLOR_TYPE_TRUECOLOR_ALPHA):
			$this->bitsPerPixel *= 4;
			break;
		}
		
		$this->progressive = ($bytes[26] & 0xff) != 0;
        return true;
    }
    
	private function checkPsd() 
	{
        $bytes = $this->read(24);
        
		if (sizeof($bytes) != 24)
            return false;
		
        $PSD_MAGIC = array(0x50, 0x53);
        if (!$this->equals($bytes, 0, $PSD_MAGIC, 0, 2))
            return false;
		
        $this->format = self::FORMAT_PSD;
        $this->width = $this->getIntBigEndian($bytes, 16);
        $this->height = $this->getIntBigEndian($bytes, 12);
        $channels = $this->getShortBigEndian($bytes, 10);
        $depth = $this->getShortBigEndian($bytes, 20);
		$this->bitsPerPixel = $channels * $depth;
        
		return ($this->width > 0 && $this->height > 0 && $this->bitsPerPixel > 0 && $this->bitsPerPixel <= 64);
    }
	
	private function setPhysicalHeightDpi($value)
	{
		$this->physicalWidthDpi = $value;
    }
    
    private function setPhysicalWidthDpi($value)
	{
		$this->physicalHeightDpi = $value;
    }
	
    private function addComment($comment)
	{
		$this->comments[] = trim($comment);
	}
	
    /**
     * @return file format as a FORMAT_xyz constant
     */
    public function getFormat()
	{
        return $this->format;
    }
	
    private function getIntBigEndian($bytes, $offs)
	{
        return
			($bytes[$offs] & 0xff) << 24 |
			($bytes[$offs + 1] & 0xff) << 16 |
			($bytes[$offs + 2] & 0xff) << 8 |
			$bytes[$offs + 3] & 0xff;
    }
	
	private function getIntLittleEndian($bytes, $offs)
	{
        return
			($bytes[$offs + 3] & 0xff) << 24 |
			($bytes[$offs + 2] & 0xff) << 16 |
			($bytes[$offs + 1] & 0xff) << 8 |
			$bytes[$offs] & 0xff;
    }
	
    private function getShortBigEndian($bytes, $offs)
	{
        return ($bytes[$offs] & 0xff) << 8 | ($bytes[$offs + 1] & 0xff);
    }
	
    private function getShortLittleEndian($bytes, $offs)
	{
        return ($bytes[$offs] & 0xff) | ($bytes[$offs + 1] & 0xff) << 8;
    }
	
	/**
	 * Read more bytes
	 * @return array
	 */
	private function read($num=1, $unpack='C*')
	{
		if ($this->_handle)
		{
			$buffer = fread($this->_handle, $num);
			if ($unpack)
			{
				$buffer = unpack($unpack, $buffer);
				return array_values($buffer);
			}
			return $buffer;
		}
	}

	/**
	 * Read single byte
	 * @return int
	 */
	private function readByte()
	{
		$bytes = $this->read(1);
		return $bytes[0];
	}
	
	/**
	 * Read string bytes
	 * @return string
	 */
	private function readString($num=1)
	{
		return $this->showBytes($this->read($num));
	}

	/**
	 * Read integer bytes
	 * @return int
	 */
	private function readInt()
	{
		$bytes = $this->read(4);
        return ($bytes[3] & 0xff) << 24 | ($bytes[2] & 0xff) << 16 | ($bytes[1] & 0xff) << 8 | $bytes[0] & 0xff;
    }

	/**
	 * Read big integer bytes
	 * @return int
	 */
    private function readBigInt()
	{
		$bytes = $this->read(4);
        return ($bytes[0] & 0xff) << 24 | ($bytes[1] & 0xff) << 16 | ($bytes[2] & 0xff) << 8 | $bytes[3] & 0xff;
    }

	/**
	 * Read Short bytes
	 * @return int
	 */
    private function readShort()
	{
		$bytes = $this->read(2);
        return ($bytes[0] & 0xff) | ($bytes[1] & 0xff) << 8;
    }

	/**
	 * Read Big Short bytes
	 * @return int
	 */
    private function readBigShort($bytes=null)
	{
		$bytes = $this->read(2);
        return ($bytes[0] & 0xff) << 8 | ($bytes[1] & 0xff);
    }

	/**
	 * Reads the 8 bytes and interprets them as a double precision floating point value
	 * @return string
	 */
	private function readDouble()
	{
		$double = $this->readString(8,-1,false);
		if (BIG_ENDIAN) $double = strrev($double);
		$double = unpack("d",$double);
		return $double[1];
	}

	/**
	 * Skip bytes
	 * @return void
	 */
    private function skipBytes($num)
	{
		if ($this->_handle)
		fseek($this->_handle,$num,SEEK_CUR);
    }

	/**
	 * Show bytes
	 * @return string
	 */
	private function showBytes($bytes)
	{
		$str='';
		foreach($bytes as $byte)
			$str.=chr($byte);
		return $str;
	}

	/**
	 * Equal bytes
	 * @return boolean
	 */
    private function equals($b1, $offs1, $b2, $offs2, $num)
	{
        while ($num-- > 0)
		{
            if ($b1[$offs1++] != $b2[$offs2++])
			{
                return false;
            }
        }
        return true;
    }
}
