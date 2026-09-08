import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

import '../css/app.css';
import Welcome from '@/pages/welcome';

const container = document.getElementById('app');

if (container) {
    createRoot(container).render(
        <StrictMode>
            <Welcome />
        </StrictMode>,
    );
}
