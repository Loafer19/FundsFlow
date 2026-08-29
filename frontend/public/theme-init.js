const theme = localStorage.getItem('theme') || 'bumblebee'

document.documentElement.setAttribute('data-theme', theme)
