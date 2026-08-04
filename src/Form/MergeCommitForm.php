<?php declare(strict_types=1);

namespace MergeItems\Form;

use Laminas\Form\Form;

class MergeCommitForm extends Form
{
    public function __construct($name = 'merge_items_commit', array $options = [])
    {
        parent::__construct($name, $options);
    }
}
