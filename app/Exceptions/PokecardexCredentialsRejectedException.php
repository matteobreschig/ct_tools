<?php

namespace App\Exceptions;

/**
 * Pokecardex ha rifiutato username e password. Nessun nuovo tentativo finché
 * l'utente non salva di nuovo le credenziali nel profilo.
 */
class PokecardexCredentialsRejectedException extends PokecardexException {}
