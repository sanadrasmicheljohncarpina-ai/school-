<?php
/*
 * Drop-in replacement for sq_questions() in security.php.
 * Replace the existing function body with this one.
 * The array KEY is what gets saved in the database; the VALUE is the text shown.
 */
function sq_questions(): array {
    return [
        'nickname'       => 'What is your nickname?',
        'called_by'      => 'What do people usually call you?',
        'fav_food'       => 'What is your favorite food?',
        'fav_color'      => 'What is your favorite color?',
        'fav_animal'     => 'What is your favorite animal?',
        'fav_subject'    => 'What is your favorite subject in school?',
        'fav_fruit'      => 'What is your favorite fruit?',
        'fav_drink'      => 'What is your favorite drink?',
        'fav_sport'      => 'What is your favorite sport or game?',
        'fav_song'       => 'What is your favorite song or singer?',
        'pet_name'       => 'What is the name of your pet (or the pet you wish to have)?',
        'best_friend'    => 'What is your best friend\'s first name?',
        'fav_place'      => 'What is your favorite place to go to?',
        'birth_month'    => 'In what month were you born?',
        'hometown'       => 'What town or barangay do you live in?',
    ];
}
