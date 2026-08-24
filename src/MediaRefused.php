<?php

namespace Flowiteam\PublicaConnector;

use RuntimeException;

/**
 * This site will not hold that file, and here is why in one sentence.
 *
 * The message travels: the controller answers 422 with it, PUBLICA records it
 * on the publication and shows it to whoever published the article. So it is
 * written for the person who runs the coffee shop — "the file is larger than
 * this site accepts (8 MB)", not "413".
 *
 * Anything else — a disk that is full, a driver that throws — is not this and
 * must not be caught into it: those are 500s, and the site's own log gets them.
 */
class MediaRefused extends RuntimeException {}
