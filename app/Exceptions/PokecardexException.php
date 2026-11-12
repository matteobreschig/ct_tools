<?php

namespace App\Exceptions;

/**
 * Pokecardex ha risposto in modo inatteso: login impossibile, sessione
 * rifiutata, risposta non decifrabile o scrittura fallita. Chi sincronizza
 * deve fermarsi prima di qualunque altra scrittura.
 */
class PokecardexException extends \RuntimeException {}
