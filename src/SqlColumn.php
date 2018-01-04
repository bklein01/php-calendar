<?php
/*
 * Copyright Sean Proctor
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace PhpCalendar;

class SqlColumn
{
    /**
     * @var string $name
     */
    public $name;
    /**
     * @var string $type
     */
    public $type;

    /**
     * SqlColumn constructor.
     *
     * @param string $name
     * @param string $type
     */
    public function __construct($name, $type)
    {
        $this->name = $name;
        $this->type = $type;
    }

    /**
     * @return string
     */
    public function getCreateQuery()
    {
        return "`{$this->name}` {$this->type}";
    }

    /**
     * @return string
     */
    public function getAddQuery()
    {
        return "ADD `{$this->name}` {$this->type}";
    }

    /**
     * @return string
     */
    public function getUpdateQuery()
    {
        return "MODIFY `{$this->name}` {$this->type}";
    }
}
