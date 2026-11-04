import axios from 'axios';
import { applyCsrfToken, readMetaCsrfToken, readXsrfCookieToken } from '@/lib/csrf';

window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true;

const token = readMetaCsrfToken() || readXsrfCookieToken();
if (token) {
    applyCsrfToken(token);
}
