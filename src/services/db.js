const DB_NAME = 'FundsFlowDB'
const DB_VERSION = 1
const STORE_NAME = 'transactions'

async function openDB() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION)

        request.onerror = (event) => {
            reject(new Error(event.target.errorCode))
        }

        request.onsuccess = (event) => {
            resolve(event.target.result)
        }

        request.onupgradeneeded = (event) => {
            const db = event.target.result

            if (!db.objectStoreNames.contains(STORE_NAME)) {
                const store = db.createObjectStore(STORE_NAME, { keyPath: 'id', autoIncrement: true })
                store.createIndex('date', 'date', { unique: false })
            }
        }
    })
}

export async function DB_Transactions_All() {
    const db = await openDB()

    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_NAME, 'readonly')
        const store = tx.objectStore(STORE_NAME)
        const index = store.index('date')
        const request = index.getAll()

        request.onsuccess = (event) => {
            resolve(event.target.result)
        }

        request.onerror = (event) => {
            reject(new Error(event.target.errorCode))
        }

        tx.oncomplete = () => {
            db.close()
        }
    })
}

export async function DB_Transactions_Add(transaction) {
    const db = await openDB()

    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_NAME, 'readwrite')
        const store = tx.objectStore(STORE_NAME)

        const request = store.add(transaction)

        request.onsuccess = (event) => {
            resolve(event.target.result)
        }

        request.onerror = (event) => {
            reject(new Error(event.target.errorCode))
        }

        tx.oncomplete = () => {
            db.close()
        }
    })
}

export async function DB_Transactions_Delete(id) {
    const db = await openDB()

    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_NAME, 'readwrite')
        const store = tx.objectStore(STORE_NAME)

        const request = store.delete(id)

        request.onsuccess = (event) => {
            resolve(event.target.result)
        }

        request.onerror = (event) => {
            reject(new Error(event.target.errorCode))
        }

        tx.oncomplete = () => {
            db.close()
        }
    })
}
