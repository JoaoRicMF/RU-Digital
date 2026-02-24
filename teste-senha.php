<?php
echo password_hash('123456', PASSWORD_BCRYPT, ['cost' => 12]);