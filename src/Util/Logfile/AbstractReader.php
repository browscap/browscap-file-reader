<?php
declare(strict_types = 1);

namespace BrowscapReader\Util\Logfile;

/**
 * abstract parent class for all readers
 */
abstract class AbstractReader implements ReaderInterface
{
    /**
     * @param string $line
     *
     * @return bool
     */
    public function test(string $line) : bool
    {
        $matches = $this->match($line);

        return array_key_exists('userAgentString', $matches);
    }

    /**
     * @param string $line
     *
     * @throws \BrowscapReader\Util\Logfile\ReaderException
     *
     * @return string
     */
    public function read(string $line) : string
    {
        $matches = $this->match($line);

        if (! array_key_exists('userAgentString', $matches)) {
            throw ReaderException::userAgentParserError($line);
        }

        return $matches['userAgentString'];
    }

    /**
     * @param string $line
     *
     * @return array
     */
    protected function match(string $line) : array
    {
        $matches = [];

        if (preg_match($this->getRegex(), $line, $matches)) {
            return $matches;
        }

        return [];
    }

    /**
     * @return string
     */
    abstract protected function getRegex() : string;
}
